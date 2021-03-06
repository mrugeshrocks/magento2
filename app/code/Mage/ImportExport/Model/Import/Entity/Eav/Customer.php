<?php
/**
 * Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Magento to newer
 * versions in the future. If you wish to customize Magento for your
 * needs please refer to http://www.magentocommerce.com for more information.
 *
 * @category    Mage
 * @package     Mage_ImportExport
 * @copyright   Copyright (c) 2013 X.commerce, Inc. (http://www.magentocommerce.com)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/**
 * Import entity customer model
 *
 * @category    Mage
 * @package     Mage_ImportExport
 * @author      Magento Core Team <core@magentocommerce.com>
 *
 * @todo finish moving dependencies to constructor in the scope of
 * @todo https://wiki.magento.com/display/MAGE2/Technical+Debt+%28Team-Donetsk-B%29
 */
class Mage_ImportExport_Model_Import_Entity_Eav_Customer
    extends Mage_ImportExport_Model_Import_Entity_Eav_CustomerAbstract
{
    /**
     * Attribute collection name
     */
    const ATTRIBUTE_COLLECTION_NAME = 'Mage_Customer_Model_Resource_Attribute_Collection';

    /**#@+
     * Permanent column names
     *
     * Names that begins with underscore is not an attribute. This name convention is for
     * to avoid interference with same attribute name.
     */
    const COLUMN_EMAIL   = 'email';
    const COLUMN_STORE   = '_store';
    /**#@-*/

    /**#@+
     * Error codes
     */
    const ERROR_DUPLICATE_EMAIL_SITE = 'duplicateEmailSite';
    const ERROR_ROW_IS_ORPHAN        = 'rowIsOrphan';
    const ERROR_INVALID_STORE        = 'invalidStore';
    const ERROR_EMAIL_SITE_NOT_FOUND = 'emailSiteNotFound';
    const ERROR_PASSWORD_LENGTH      = 'passwordLength';
    /**#@-*/

    /**#@+
     * Keys which used to build result data array for future update
     */
    const ENTITIES_TO_CREATE_KEY = 'entities_to_create';
    const ENTITIES_TO_UPDATE_KEY = 'entities_to_update';
    const ATTRIBUTES_TO_SAVE_KEY = 'attributes_to_save';
    /**#@-*/

    /**
     * Minimum password length
     */
    const MIN_PASSWORD_LENGTH = 6;

    /**
     * Default customer group
     */
    const DEFAULT_GROUP_ID = 1;

    /**
     * Customers information from import file
     *
     * @var array
     */
    protected $_newCustomers = array();

    /**
     * Array of attribute codes which will be ignored in validation and import procedures.
     * For example, when entity attribute has own validation and import procedures
     * or just to deny this attribute processing.
     *
     * @var array
     */
    protected $_ignoredAttributes = array('website_id', 'store_id');

    /**
     * Customer entity DB table name.
     *
     * @var string
     */
    protected $_entityTable;

    /**
     * Customer model
     *
     * @var Mage_Customer_Model_Customer
     */
    protected $_customerModel;

    /**
     * Id of next customer entity row
     *
     * @var int
     */
    protected $_nextEntityId;

    /**
     * Address attributes collection
     *
     * @var Mage_Customer_Model_Resource_Attribute_Collection
     */
    protected $_attributeCollection;

    /**
     * Constructor
     *
     * @param array $data
     */
    public function __construct(array $data = array())
    {
        if (isset($data['attribute_collection'])) {
            $this->_attributeCollection = $data['attribute_collection'];
            unset($data['attribute_collection']);
        } else {
            $this->_attributeCollection = Mage::getResourceModel(static::ATTRIBUTE_COLLECTION_NAME);
            $this->_attributeCollection->addSystemHiddenFilterWithPasswordHash();
            $data['attribute_collection'] = $this->_attributeCollection;
        }

        parent::__construct($data);

        $this->_specialAttributes[] = self::COLUMN_WEBSITE;
        $this->_specialAttributes[] = self::COLUMN_STORE;
        $this->_permanentAttributes[]  = self::COLUMN_EMAIL;
        $this->_permanentAttributes[]  = self::COLUMN_WEBSITE;
        $this->_indexValueAttributes[] = 'group_id';

        $this->addMessageTemplate(self::ERROR_DUPLICATE_EMAIL_SITE,
            $this->_helper('Mage_ImportExport_Helper_Data')->__('E-mail is duplicated in import file')
        );
        $this->addMessageTemplate(self::ERROR_ROW_IS_ORPHAN,
            $this->_helper('Mage_ImportExport_Helper_Data')
                ->__('Orphan rows that will be skipped due default row errors')
        );
        $this->addMessageTemplate(self::ERROR_INVALID_STORE,
            $this->_helper('Mage_ImportExport_Helper_Data')
                ->__('Invalid value in Store column (store does not exists?)')
        );
        $this->addMessageTemplate(self::ERROR_EMAIL_SITE_NOT_FOUND,
            $this->_helper('Mage_ImportExport_Helper_Data')->__('E-mail and website combination is not found')
        );
        $this->addMessageTemplate(self::ERROR_PASSWORD_LENGTH,
            $this->_helper('Mage_ImportExport_Helper_Data')->__('Invalid password length')
        );

        $this->_initStores(true)
            ->_initAttributes();

        $this->_customerModel = Mage::getModel('Mage_Customer_Model_Customer');
        /** @var $customerResource Mage_Customer_Model_Resource_Customer */
        $customerResource = $this->_customerModel->getResource();
        $this->_entityTable = $customerResource->getEntityTable();
    }

    /**
     * Update and insert data in entity table
     *
     * @param array $entitiesToCreate Rows for insert
     * @param array $entitiesToUpdate Rows for update
     * @return Mage_ImportExport_Model_Import_Entity_Eav_Customer
     */
    protected function _saveCustomerEntities(array $entitiesToCreate, array $entitiesToUpdate)
    {
        if ($entitiesToCreate) {
            $this->_connection->insertMultiple($this->_entityTable, $entitiesToCreate);
        }

        if ($entitiesToUpdate) {
            $this->_connection->insertOnDuplicate(
                $this->_entityTable,
                $entitiesToUpdate,
                array('group_id', 'store_id', 'updated_at', 'created_at')
            );
        }

        return $this;
    }

    /**
     * Save customer attributes.
     *
     * @param array $attributesData
     * @return Mage_ImportExport_Model_Import_Entity_Eav_Customer
     */
    protected function _saveCustomerAttributes(array $attributesData)
    {
        foreach ($attributesData as $tableName => $data) {
            $tableData = array();

            foreach ($data as $customerId => $attributeData) {
                foreach ($attributeData as $attributeId => $value) {
                    $tableData[] = array(
                        'entity_id'      => $customerId,
                        'entity_type_id' => $this->getEntityTypeId(),
                        'attribute_id'   => $attributeId,
                        'value'          => $value
                    );
                }
            }
            $this->_connection->insertOnDuplicate($tableName, $tableData, array('value'));
        }
        return $this;
    }

    /**
     * Delete list of customers
     *
     * @param array $entitiesToDelete customers id list
     * @return Mage_ImportExport_Model_Import_Entity_Eav_Customer
     */
    protected function _deleteCustomerEntities(array $entitiesToDelete)
    {
        $condition = $this->_connection->quoteInto('entity_id IN (?)', $entitiesToDelete);
        $this->_connection->delete($this->_entityTable, $condition);

        return $this;
    }

    /**
     * Retrieve next customer entity id
     *
     * @return int
     */
    protected function _getNextEntityId()
    {
        if (!$this->_nextEntityId) {
            /** @var $resourceHelper Mage_ImportExport_Model_Resource_Helper_Mysql4 */
            $resourceHelper = Mage::getResourceHelper('Mage_ImportExport');
            $this->_nextEntityId = $resourceHelper->getNextAutoincrement($this->_entityTable);
        }
        return $this->_nextEntityId++;
    }

    /**
     * Prepare customer data for update
     *
     * @param array $rowData
     * @return array
     */
    protected function _prepareDataForUpdate(array $rowData)
    {
        /** @var $passwordAttribute Mage_Customer_Model_Attribute */
        $passwordAttribute = $this->_customerModel->getAttribute('password_hash');
        $passwordAttributeId = $passwordAttribute->getId();
        $passwordStorageTable = $passwordAttribute->getBackend()->getTable();

        $entitiesToCreate = array();
        $entitiesToUpdate = array();
        $attributesToSave = array();

        // entity table data
        $now = new DateTime('@' . time());
        if (empty($rowData['created_at'])) {
            $createdAt = $now;
        } else {
            $createdAt = new DateTime('@' . strtotime($rowData['created_at']));
        }
        $entityRow = array(
            'group_id'   => empty($rowData['group_id'])
                ? self::DEFAULT_GROUP_ID : $rowData['group_id'],

            'store_id'   => empty($rowData[self::COLUMN_STORE])
                ? 0 : $this->_storeCodeToId[$rowData[self::COLUMN_STORE]],

            'created_at' => $createdAt->format(Varien_Date::DATETIME_PHP_FORMAT),
            'updated_at' => $now->format(Varien_Date::DATETIME_PHP_FORMAT),
        );

        $emailInLowercase = strtolower($rowData[self::COLUMN_EMAIL]);
        if ($entityId = $this->_getCustomerId($emailInLowercase, $rowData[self::COLUMN_WEBSITE])) { // edit
            $entityRow['entity_id'] = $entityId;
            $entitiesToUpdate[] = $entityRow;
        } else { // create
            $entityId = $this->_getNextEntityId();
            $entityRow['entity_id'] = $entityId;
            $entityRow['entity_type_id'] = $this->getEntityTypeId();
            $entityRow['attribute_set_id'] = 0;
            $entityRow['website_id'] = $this->_websiteCodeToId[$rowData[self::COLUMN_WEBSITE]];
            $entityRow['email'] = $emailInLowercase;
            $entityRow['is_active'] = 1;
            $entitiesToCreate[] = $entityRow;

            $this->_newCustomers[$emailInLowercase][$rowData[self::COLUMN_WEBSITE]] = $entityId;
        }

        // attribute values
        foreach (array_intersect_key($rowData, $this->_attributes) as $attributeCode => $value) {
            if (!$this->_attributes[$attributeCode]['is_static'] && strlen($value)) {
                /** @var $attribute Mage_Customer_Model_Attribute */
                $attribute = $this->_customerModel->getAttribute($attributeCode);
                $backendModel = $attribute->getBackendModel();
                $attributeParameters = $this->_attributes[$attributeCode];

                if ('select' == $attributeParameters['type']) {
                    $value = $attributeParameters['options'][strtolower($value)];
                } elseif ('datetime' == $attributeParameters['type']) {
                    $value = new DateTime('@' . strtotime($value));
                    $value = $value->format(Varien_Date::DATETIME_PHP_FORMAT);
                } elseif ($backendModel) {
                    $attribute->getBackend()->beforeSave($this->_customerModel->setData($attributeCode, $value));
                    $value = $this->_customerModel->getData($attributeCode);
                }
                $attributesToSave[$attribute->getBackend()->getTable()][$entityId][$attributeParameters['id']]
                    = $value;

                // restore 'backend_model' to avoid default setting
                $attribute->setBackendModel($backendModel);
            }
        }

        // password change/set
        if (isset($rowData['password']) && strlen($rowData['password'])) {
            $attributesToSave[$passwordStorageTable][$entityId][$passwordAttributeId]
                = $this->_customerModel->hashPassword($rowData['password']);
        }

        return array(
            self::ENTITIES_TO_CREATE_KEY => $entitiesToCreate,
            self::ENTITIES_TO_UPDATE_KEY => $entitiesToUpdate,
            self::ATTRIBUTES_TO_SAVE_KEY => $attributesToSave
        );
    }

    /**
     * Import data rows
     *
     * @return boolean
     */
    protected function _importData()
    {
        while ($bunch = $this->_dataSourceModel->getNextBunch()) {
            $entitiesToCreate = array();
            $entitiesToUpdate = array();
            $entitiesToDelete = array();
            $attributesToSave = array();

            foreach ($bunch as $rowNumber => $rowData) {
                if (!$this->validateRow($rowData, $rowNumber)) {
                    continue;
                }

                if ($this->getBehavior($rowData) == Mage_ImportExport_Model_Import::BEHAVIOR_DELETE) {
                    $entitiesToDelete[] = $this->_getCustomerId(
                        $rowData[self::COLUMN_EMAIL],
                        $rowData[self::COLUMN_WEBSITE]
                    );
                } elseif ($this->getBehavior($rowData) == Mage_ImportExport_Model_Import::BEHAVIOR_ADD_UPDATE) {
                    $processedData = $this->_prepareDataForUpdate($rowData);
                    $entitiesToCreate = array_merge($entitiesToCreate, $processedData[self::ENTITIES_TO_CREATE_KEY]);
                    $entitiesToUpdate = array_merge($entitiesToUpdate, $processedData[self::ENTITIES_TO_UPDATE_KEY]);
                    foreach ($processedData[self::ATTRIBUTES_TO_SAVE_KEY] as $tableName => $customerAttributes) {
                        if (!isset($attributesToSave[$tableName])) {
                            $attributesToSave[$tableName] = array();
                        }
                        $attributesToSave[$tableName] =
                            array_diff_key($attributesToSave[$tableName], $customerAttributes) + $customerAttributes;
                    }
                }
            }
            /**
             * Save prepared data
             */
            if ($entitiesToCreate || $entitiesToUpdate) {
                $this->_saveCustomerEntities($entitiesToCreate, $entitiesToUpdate);
            }
            if ($attributesToSave) {
                $this->_saveCustomerAttributes($attributesToSave);
            }
            if ($entitiesToDelete) {
                $this->_deleteCustomerEntities($entitiesToDelete);
            }
        }

        return true;
    }

    /**
     * EAV entity type code getter
     *
     * @return string
     */
    public function getEntityTypeCode()
    {
        return $this->_attributeCollection->getEntityTypeCode();
    }

    /**
     * Validate row data for add/update behaviour
     *
     * @param array $rowData
     * @param int $rowNumber
     * @return null
     */
    protected function _validateRowForUpdate(array $rowData, $rowNumber)
    {
        if ($this->_checkUniqueKey($rowData, $rowNumber)) {
            $email   = strtolower($rowData[self::COLUMN_EMAIL]);
            $website = $rowData[self::COLUMN_WEBSITE];

            if (isset($this->_newCustomers[strtolower($rowData[self::COLUMN_EMAIL])][$website])) {
                $this->addRowError(self::ERROR_DUPLICATE_EMAIL_SITE, $rowNumber);
            }
            $this->_newCustomers[$email][$website] = false;

            if (!empty($rowData[self::COLUMN_STORE]) && !isset($this->_storeCodeToId[$rowData[self::COLUMN_STORE]])) {
                $this->addRowError(self::ERROR_INVALID_STORE, $rowNumber);
            }
            // check password
            if (isset($rowData['password']) && strlen($rowData['password'])
                && $this->_stringHelper->strlen($rowData['password']) < self::MIN_PASSWORD_LENGTH
            ) {
                $this->addRowError(self::ERROR_PASSWORD_LENGTH, $rowNumber);
            }
            // check simple attributes
            foreach ($this->_attributes as $attributeCode => $attributeParams) {
                if (in_array($attributeCode, $this->_ignoredAttributes)) {
                    continue;
                }
                if (isset($rowData[$attributeCode]) && strlen($rowData[$attributeCode])) {
                    $this->isAttributeValid($attributeCode, $attributeParams, $rowData, $rowNumber);
                } elseif ($attributeParams['is_required'] && !$this->_getCustomerId($email, $website)) {
                    $this->addRowError(self::ERROR_VALUE_IS_REQUIRED, $rowNumber, $attributeCode);
                }
            }
        }
    }

    /**
     * Validate row data for delete behaviour
     *
     * @param array $rowData
     * @param int $rowNumber
     * @return null
     */
    protected function _validateRowForDelete(array $rowData, $rowNumber)
    {
        if ($this->_checkUniqueKey($rowData, $rowNumber)) {
            if (!$this->_getCustomerId($rowData[self::COLUMN_EMAIL], $rowData[self::COLUMN_WEBSITE])) {
                $this->addRowError(self::ERROR_CUSTOMER_NOT_FOUND, $rowNumber);
            }
        }
    }

    /**
     * Entity table name getter
     *
     * @return string
     */
    public function getEntityTable()
    {
        return $this->_entityTable;
    }
}
