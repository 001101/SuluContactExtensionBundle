<?php
/*
 * This file is part of the Sulu CMS.
 *
 * (c) MASSIVE ART WebServices GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\ContactExtensionBundle\Import;

use DateTime;
use Doctrine\DBAL\DBALException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\NonUniqueResultException;
use Sulu\Bundle\ContactBundle\Contact\AbstractContactManager;
use Sulu\Bundle\ContactBundle\Contact\AccountFactoryInterface;
use Sulu\Bundle\ContactBundle\Contact\AccountManager;
use Sulu\Bundle\ContactBundle\Contact\ContactManager;
use Sulu\Bundle\ContactBundle\Entity\AccountAddress;
use Sulu\Bundle\ContactBundle\Entity\AccountContact;
use Sulu\Bundle\ContactBundle\Entity\AccountInterface;
use Sulu\Bundle\ContactBundle\Entity\Address;
use Sulu\Bundle\ContactBundle\Entity\BankAccount;
use Sulu\Bundle\ContactBundle\Entity\ContactTitle;
use Sulu\Bundle\ContactBundle\Entity\Email;
use Sulu\Bundle\ContactBundle\Entity\Fax;
use Sulu\Bundle\ContactBundle\Entity\Note;
use Sulu\Bundle\ContactBundle\Entity\Phone;
use Sulu\Bundle\ContactBundle\Entity\Position;
use Sulu\Bundle\ContactBundle\Entity\Url;
use Sulu\Bundle\ContactExtensionBundle\Entity\Account;
use Sulu\Bundle\ContactExtensionBundle\Import\Exception\ImportException;
use Sulu\Bundle\TagBundle\Entity\Tag;
use Sulu\Component\Contact\Model\ContactInterface;
use Sulu\Component\Contact\Model\ContactRepositoryInterface;
use Sulu\Component\Rest\Exception\EntityNotFoundException;
use Symfony\Component\Translation\Exception\NotFoundResourceException;

/**
 * Configures and executes an import for contact and account data from a CSV file.
 */
class Import
{
    const DEBUG = true;
    const MAX_POSITION_LENGTH = 60;

    /**
     * Options for Import
     *
     * @var array
     *
     * Description
     * - {string=;} delimiter Delimiter that is used for csv import.
     * - {string="} enclosure Enclosure that is used for csv import.
     * - {Boolean=true} importContactByIds Defines if contacts should be imported by ids of import file.
     * - {Boolean=false} streetNumberSplit Defines if street is provided as street- number string and must be
     *    splitted.
     * - {Boolean=false|int} fixedAccountType Defines if accountType should be set to a fixed type for all
     *    imported accounts.
     * - {Boolean=array} contactComparisonCriteria Array that defines which data should be used to identify if a
     *    contact already exists in Database. This will only work if contact_id is not provided (then of course id is
     *    being used for this purpose) parameters can be: firstName, lastName, email and phone.
     */
    protected $options = [
        'importContactByIds' => false,
        'streetNumberSplit' => false,
        'delimiter' => ';',
        'enclosure' => '"',
        'contactComparisonCriteria' => array(
            'firstName',
            'lastName',
            'email',
        ),
        'fixedAccountType' => false,
    ];

    /**
     * Defines which columns should be excluded,
     *  - either with simple key value (e.g. 'column_exclude' => 'true')
     *
     * @var array
     */
    protected $excludeConditions = array();

    /**
     * Defaults that are set for a specific key (if not set in data) e.g.: address1_label = 'mobile'.
     *
     * @var array
     */
    protected $defaults = array();

    /**
     * Define entity names
     */
    protected $accountContactEntityName = 'SuluContactBundle:AccountContact';
    protected $tagEntityName = 'SuluTagBundle:Tag';
    protected $titleEntityName = 'SuluContactBundle:ContactTitle';
    protected $positionEntityName = 'SuluContactBundle:Position';
    protected $countryEntityName = 'SuluContactBundle:Country';

    /**
     * @var EntityManager
     */
    protected $em;

    /**
     * @var AbstractContactManager $accountManager
     */
    protected $accountManager;

    /**
     * @var AbstractContactManager $contactManager
     */
    protected $contactManager;

    /**
     * Location of contacts import file.
     *
     * @var string $contactFile
     */
    private $contactFile;

    /**
     * Location of accounts import file.
     *
     * @var string $accountFile
     */
    private $accountFile;

    /**
     * Location of the mappings file.
     *
     * @var string $mappingsFile
     */
    private $mappingsFile;

    /**
     * Default values for different types, as defined in config (emailType, phoneType,..).
     *
     * @var array $configDefaults
     */
    protected $configDefaults;
    /**
     * @var array $configAccountTypes
     */
    protected $configAccountTypes;
    /**
     * Different forms of address.
     *
     * @var $configFormOfAddress
     */
    protected $configFormOfAddress;

    /**
     * Limit of rows to import.
     *
     * @var int
     */
    private $limit;

    /**
     * @var array
     */
    protected $defaultTypes = array();

    /**
     * Storage for log messages.
     *
     * @var array
     */
    protected $log = array();

    /**
     * Storage of csv header data.
     *
     * @var array
     */
    protected $headerData = array();

    /**
     * Current row-number.
     *
     * @var int
     */
    protected $rowNumber;

    /**
     * Holds the amount of header variables.
     *
     * @var int
     */
    protected $headerCount;

    /**
     * Defines mappings of columns in import file.
     *
     * @var array
     *
     * defaults are:
     * 'account_name'
     * 'account_type'
     * 'account_division'
     * 'account_disabled'
     * 'account_uid'
     * 'account_registerNumber'
     * 'account_tag'
     * 'email1' (1..n)
     * 'url1' (1..n)
     * 'note1' (1..n)
     * 'phone1' (1..n)
     * 'phone_isdn'
     * 'phone_mobile'
     * 'country'
     * 'plz'
     * 'street'
     * 'city'
     * 'fax'
     * 'contact_parent'
     * 'contact_title'
     * 'contact_position'
     * 'contact_firstname'
     * 'contact_lastname'
     * contact_formOfAddress
     * contact_salutation
     * contact_birthday
     *
     */
    protected $columnMappings = array();

    /**
     * Defines mappings of ids in import file.
     *
     * @var array
     */
    protected $idMappings = array(
        'account_id' => 'account_id'
    );

    /**
     * @var array
     */
    protected $countryMappings = array();

    /**
     * Mappings for form of address.
     *
     * @var array
     */
    protected $formOfAddressMappings = array();

    /**
     * Defines mappings of accountTypes in import file.
     *
     * @var array
     */
    protected $accountTypeMappings = array(
        'basic' => Account::TYPE_BASIC,
        'lead' => Account::TYPE_LEAD,
        'customer' => Account::TYPE_CUSTOMER,
        'supplier' => Account::TYPE_SUPPLIER,
    );

    /**
     * Defines mappings of address / url / email / phone / fax types in import file.
     *
     * @var array
     */
    protected $contactLabelMappings = array(
        'work' => 'work',
        'home' => 'home',
        'mobile' => 'mobile',
    );

    /**
     * Used as temp storage for newly created accounts.
     *
     * @var array
     */
    protected $accountExternalIds = array();

    /**
     * Used as temp associative storage for newly created accounts.
     *
     * @var array
     */
    protected $associativeAccounts = array();

    /**
     * Used as temp storage for account categories.
     *
     * @var array
     */
    protected $accountCategories = array();

    /**
     * Used as temp storage for tags.
     *
     * @var array
     */
    protected $tags = array();

    /**
     * Used as temp storage for titles.
     *
     * @var array
     */
    protected $titles = array();

    /**
     * Used as temp storage for positions.
     *
     * @var array
     */
    protected $positions = array();

    /**
     * Defines possible new-line characters that should be replaced.
     * e.g. '¶'
     *
     * @var array
     */
    protected $invalidNewLineCharacters = array();

    /**
     * @var AccountFactoryInterface
     */
    private $accountFactory;

    /**
     * @var EntityRepository
     */
    protected $accountRepository;

    /**
     * @var ContactRepositoryInterface
     */
    protected $contactRepository;

    /**
     * @param EntityManager $em
     * @param AccountManager $accountManager
     * @param ContactManager $contactManager
     * @param AccountFactoryInterface $accountFactory
     * @param array $configDefaults
     * @param array $configAccountTypes
     * @param array $configFormOfAddress
     * @param EntityRepository $accountRepository
     * @param EntityRepository $contactRepository
     */
    public function __construct(
        EntityManager $em,
        AccountManager $accountManager,
        ContactManager $contactManager,
        AccountFactoryInterface $accountFactory,
        $configDefaults,
        $configAccountTypes,
        $configFormOfAddress,
        EntityRepository $accountRepository,
        EntityRepository $contactRepository
    ) {
        $this->em = $em;
        $this->configDefaults = $configDefaults;
        $this->configAccountTypes = $configAccountTypes;
        $this->configFormOfAddress = $configFormOfAddress;
        $this->accountManager = $accountManager;
        $this->contactManager = $contactManager;
        $this->accountFactory = $accountFactory;
        $this->accountRepository = $accountRepository;
        $this->contactRepository = $contactRepository;
    }

    /**
     * Executes the import.
     */
    public function execute()
    {
        // Enable garbage collector.
        gc_enable();
        // Disable sql logger.
        $this->em->getConnection()->getConfiguration()->setSQLLogger(null);

        try {
            // Process mappings file.
            if ($this->mappingsFile) {
                $this->processMappingsFile($this->mappingsFile);
            }

            // TODO clear database: $this->clearDatabase();

            // Process account file if exists.
            if ($this->accountFile) {
                $this->processAccountFile($this->accountFile);
            }

            // Process contact file if exists.
            if ($this->contactFile) {
                $this->processContactFile($this->contactFile);
            }
        } catch (\Exception $e) {
            print($e->getMessage());
            throw $e;
        }
    }

    /**
     * Loads type defaults, tags and account-categories
     * gets called by processcsvloop.
     */
    protected function initDefaults()
    {
        // Set default types.
        $this->defaultTypes = $this->getDefaults();
        $this->loadTags();
        $this->loadTitles();
        $this->loadPositions();
    }

    /**
     * Assigns mappings as defined in mappings file.
     *
     * @param $mappingsFile
     *
     * @throws \Symfony\Component\Translation\Exception\NotFoundResourceException
     *
     * @return bool|mixed
     */
    protected function processMappingsFile($mappingsFile)
    {
        try {
            // Set mappings.
            if ($mappingsFile && ($mappingsContent = file_get_contents($mappingsFile))) {
                $mappings = json_decode($mappingsContent, true);
                if (!$mappings) {
                    throw new \Exception('no valid JSON in mappings file');
                }
                if (array_key_exists('columns', $mappings)) {
                    $this->setColumnMappings($mappings['columns']);
                }
                if (array_key_exists('ids', $mappings)) {
                    $this->setIdMappings($mappings['ids']);
                }
                if (array_key_exists('options', $mappings)) {
                    $this->setOptions($mappings['options']);
                }
                if (array_key_exists('countries', $mappings)) {
                    $this->setCountryMappings($mappings['countries']);
                }
                if (array_key_exists('accountTypes', $mappings)) {
                    $this->setAccountTypeMappings($mappings['accountTypes']);
                }
                if (array_key_exists('formOfAddress', $mappings)) {
                    $this->setFormOfAddressMappings($mappings['formOfAddress']);
                }
                if (array_key_exists('contactLabels', $mappings)) {
                    $this->contactLabelMappings = $mappings['contactLabels'];
                }
                if (array_key_exists('defaults', $mappings)) {
                    $this->defaults = $mappings['defaults'];
                }

                return $mappings;
            }

            return false;
        } catch (\Exception $e) {
            throw new NotFoundResourceException($mappingsFile);
        }
    }

    /**
     * Processes the account file.
     *
     * @param string $filename path to file
     */
    protected function processAccountFile($filename)
    {
        // Create accounts.
        $this->debug("Create Accounts:\n");
        $this->processCsvLoop(
            $filename,
            [$this, 'createAccount']
        );

        // Check for parents.
        $this->debug("Creating Account Parent Relations:\n");
        $this->processCsvLoop(
            $filename,
            function ($data, $row) {
                $this->createAccountParentRelation($data, $row);
            }
        );
    }

    /**
     * Processes the contact file.
     *
     * @param string $filename path to file
     */
    protected function processContactFile($filename)
    {
        $createContact = function ($data, $row) {
            return $this->createContact($data, $row);
        };
        $postFlushContact = function ($data, $row, $result) {
            return call_user_func(array($this, 'postFlushCreateContact'), $data, $row, $result);
        };

        // Create contacts.
        $this->debug("Create Contacts:\n");
        $this->processCsvLoop($filename, $createContact, $postFlushContact, true);
    }

    /**
     * This function will be called after a contact was created (after flush)
     */
    protected function postFlushCreateContact($data, $row, $contact)
    {
    }

    /**
     * Loads the CSV Files and the Entities for the import.
     *
     * @param string $filename Path to file.
     * @param callable $callback Will be called for each row in file.
     * @param callable $postFlushCallback Will be called after every flush.
     * @param bool $flushOnEveryRow If defined flush will be executed on every data row.
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Exception
     */
    protected function processCsvLoop(
        $filename,
        callable $callback,
        callable $postFlushCallback = null,
        $flushOnEveryRow = false
    ) {
        // Initialize default values.
        $this->initDefaults();

        $row = 0;
        $successCount = 0;
        $errorCount = 0;
        $this->headerData = array();

        try {
            // Load all Files.
            $handle = fopen($filename, 'r');
        } catch (\Exception $e) {
            throw new NotFoundResourceException($filename);
        }

        while (($data = fgetcsv($handle, 0, $this->options['delimiter'], $this->options['enclosure'])) !== false) {
            try {
                $this->rowNumber = $row + 1;
                // For first row, save headers.
                if ($row === 0) {
                    $this->headerData = $data;
                    $this->headerCount = count($data);
                } else {
                    if ($this->headerCount !== count($data)) {
                        throw new ImportException('The number of fields does not match the number of header values');
                    }

                    // Get associativeData.
                    $associativeData = $this->mapRowToAssociativeArray($data, $this->headerData);

                    // Check if row contains data that should be excluded.
                    $exclude = $this->rowContainsExlcudeData($associativeData, $key, $value);
                    if (!$exclude) {
                        // Call callback function.
                        $result = $callback($associativeData, $row);
                    } else {
                        $this->debug(
                            sprintf(
                                "Exclude data row %d due to exclude condition %s = %s \n",
                                $this->rowNumber,
                                $key,
                                $value
                            )
                        );
                    }

                    if ($flushOnEveryRow) {
                        $this->em->flush();
                        if (!$exclude && !is_null($postFlushCallback)) {
                            $postFlushCallback($associativeData, $row, $result);
                        }
                    }
                    if ($row % 20 === 0) {
                        if (!$flushOnEveryRow) {
                            $this->em->flush();
                            if (!$exclude && !is_null($postFlushCallback)) {
                                $postFlushCallback($associativeData, $row, $result);
                            }
                        }
                        $this->em->clear();
                        gc_collect_cycles();
                        // Reinitialize defaults (lost with call of clear).
                        $this->initDefaults();
                    }

                    $successCount++;
                }
            } catch (DBALException $dbe) {
                $this->debug(sprintf("ABORTING DUE TO DATABASE ERROR: %s \n", $dbe->getMessage()));
                throw $dbe;
            } catch (\Exception $e) {
                $this->debug(
                    sprintf("\nERROR while processing data row %d: %s \n", $this->rowNumber, $e->getMessage())
                );
                $errorCount++;
            }

            // Check limit and break loop if necessary.
            $limit = $this->getLimit();
            if (!is_null($limit) && $row >= $limit) {
                break;
            }
            $row++;

            if (self::DEBUG) {
                print(sprintf("%d ", $this->rowNumber));
            }
        }
        // Finish with a flush.
        $this->em->flush();

        $this->debug("\n");
        fclose($handle);
    }

    /**
     * Checks if data row contains a value that is defined as exclude criteria (for a specific column)
     * (see excludeConditions).
     *
     * @param array $data
     * @param string|null $conditionKey
     * @param string|null $conditionValue
     *
     * @return bool
     */
    protected function rowContainsExlcudeData($data, &$conditionKey = null, &$conditionValue = null)
    {
        if (count($this->excludeConditions) > 0) {
            // Iterate through all defined exclude conditions.
            foreach ($this->excludeConditions as $key => $value) {
                if (isset($data[$key])) {
                    // If condition is an array - compare with every value.
                    if (is_array($value)) {
                        foreach ($value as $childValue) {
                            if ($this->compareStrings($childValue, $data[$key])) {
                                $conditionKey = $key;
                                $conditionValue = $value;

                                return true;
                            }
                        }
                        // Else if simple value - just compare.
                    } else {
                        // If match.
                        if ($this->compareStrings($value, $data[$key])) {
                            $conditionKey = $key;
                            $conditionValue = $value;

                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Compares two strings, by default it checks only if the string begins with the same value.
     *
     * @param string $needle
     * @param string $haystack
     * @param bool $strict
     *
     * @return bool
     */
    private function compareStrings($needle, $haystack, $strict = false)
    {
        if ($strict || empty($needle)) {
            return $needle === $haystack;
        }

        return strpos($haystack, $needle) === 0;
    }

    /**
     * Creates a new account Entity.
     *
     * @param int|null $externalId
     *
     * @return AccountInterface
     */
    protected function createNewAccount($externalId = null)
    {
        $account = $this->accountFactory->createEntity();
        if ($externalId) {
            $account->setExternalId($externalId);
        }
        $this->em->persist($account);

        return $account;
    }

    /**
     * Creates an account for given row data.
     *
     * @param array $data
     * @param int $row
     *
     * @throws \Exception
     *
     * @return AccountInterface
     */
    protected function createAccount($data, $row)
    {
        // Check if id mapping is defined.
        if (array_key_exists('account_id', $this->idMappings)) {
            if (!array_key_exists($this->idMappings['account_id'], $data)) {
                $this->accountExternalIds[] = null;
                throw new \Exception(
                    'No key ' + $this->idMappings['account_id'] + ' found in column definition of accounts file'
                );
            }
            $externalId = $data[$this->idMappings['account_id']];

            // Check if account with external-id exists.
            $account = $this->getAccountByKey($externalId);
            if (!$account) {
                // If not, create new one.
                $account = $this->createNewAccount($externalId);
            } else {
                // Otherwise, clear all relations.
                $this->em->refresh($account);

                // $this->getAccountManager()->deleteAllRelations($account);
            }
            $this->accountExternalIds[] = $externalId;
        } // Otherwise just create a new account.
        else {
            $account = $this->createNewAccount();
        }

        if ($this->checkData('account_name', $data)) {
            $account->setName(trim($data['account_name']));
        } else {
            $this->em->detach($account);
            throw new \Exception('ERROR: account name not set');
        }
        if (!$account->getName()) {
            $this->em->detach($account);
            throw new \Exception('ERROR: account name not set');
        }

        if ($this->checkData('account_corporation', $data)) {
            $account->setCorporation($data['account_corporation']);
        }
        if ($this->checkData('account_uid', $data)) {
            $account->setUid($this->removeWhiteSpaces($data['account_uid']));
        }
        if ($this->checkData('account_number', $data)) {
            $account->setNumber($data['account_number']);
        }
        if ($this->checkData('account_registerNumber', $data)) {
            $account->setRegisterNumber($data['account_registerNumber']);
        }
        if ($this->checkData('account_jurisdiction', $data)) {
            $account->setPlaceOfJurisdiction($data['account_jurisdiction']);
        }
        // Set account type.
        if ($this->options['fixedAccountType'] != false && is_numeric($this->options['fixedAccountType'])) {
            // Set account type to a fixed number.
            $account->setType($this->options['fixedAccountType']);
        } elseif ($this->checkData('account_type', $data)) {
            $account->setType($this->mapAccountType($data['account_type']));
        }

        // Process emails, phones, faxes, urls and notes.
        $this->processTags($data, $account);
        $this->processEmails($data, $account);
        $this->processPhones($data, $account);
        $this->processFaxes($data, $account);
        $this->processUrls($data, $account);
        $this->processNotes($data, $account, 'account_');

        // Add address if set.
        $address = $this->createAddresses($data, $account);
        if ($address !== null) {
            $this->getAccountManager()->addAddress($account, $address, true);
        }

        // Add bank accounts.
        $this->addBankAccounts($data, $account);

        return $account;
    }

    /**
     * Iterate through data and find first of a specific type (which is enumerable).
     *
     * @param string $identifier
     * @param array $data
     *
     * @return string|bool
     */
    protected function getFirstOf($identifier, $data)
    {
        for ($i = 0, $len = 10; ++$i < $len;) {
            if ($this->checkData($identifier . $i, $data)) {
                return $data[$identifier . $i];
            }
        }

        return false;
    }

    /**
     * Removes all white-spaces from a string.
     *
     * @param string
     *
     * @return string
     */
    protected function removeWhiteSpaces($string)
    {
        return preg_replace('/\s+/', '', $string);
    }

    /**
     * Adds emails to the entity.
     *
     * @param array $data
     * @param Entity $entity
     */
    protected function processEmails($data, $entity)
    {
        // Add emails.
        for ($i = 0, $len = 10; ++$i < $len;) {
            if ($this->checkData('email' . $i, $data)) {
                $emailIndex = 'email' . $i;
                $prefix = $emailIndex . '_';

                $email = new Email();
                $email->setEmail($data[$emailIndex]);

                // Set label.
                $type = null;
                if ($this->checkData($prefix . 'label', $data)) {
                    $contactLabel = 'email.' . $this->mapContactLabels($data[$prefix . 'label']);
                    $type = $this->getContactManager()->getEmailTypeByName($contactLabel);
                }
                if (!$type) {
                    $type = $this->defaultTypes['emailType'];
                }
                $email->setEmailType($type);

                if (!$this->isEmailAlreadyAssigned($email, $entity)) {
                    $this->em->persist($email);
                    $entity->addEmail($email);
                }
            }
        }
        $this->getContactManager()->setMainEmail($entity);
    }

    /**
     * Checks if given email is already assigned to entity.
     *
     * @param Email $search
     * @param ContactInterface|AccountInterface $entity
     *
     * @return bool
     */
    protected function isEmailAlreadyAssigned(Email $search, $entity)
    {
        /** @var Email $email */
        foreach ($entity->getEmails() as $email) {
            if ($email->getEmail() === $search->getEmail()
                && $email->getEmailType()->getId() === $search->getEmailType()->getId()
            ) {
                return true;
            }
        }

        return false;
    }
    /**
     * Checks if given address is already assigned to entity.
     *
     * @param Address $search
     * @param ContactInterface|AccountInterface $entity
     *
     * @return bool
     */
    protected function isAddressAlreadyAssigned(Address $search, $entity)
    {
        /** @var AccountAddress $email */
        foreach ($this->getManager($entity)->getAddressRelations($entity) as $addressRelation) {
            /** @var Address $address */
            $address = $addressRelation->getAddress();
            if ($address->getStreet() === $search->getStreet()
                && $address->getNumber() === $search->getNumber()
                && $address->getCity() === $search->getCity()
                && $address->getCountry() === $search->getCountry()
                && $address->getAddressType()->getId() === $search->getAddressType()->getId()
                && $address->getPostboxNumber() === $search->getPostboxNumber()
                && $address->getPostboxPostcode() === $search->getPostboxPostcode()
                && $address->getPostboxCity() === $search->getPostboxCity()
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if given phone is already assigned to entity.
     *
     * @param Phone $search
     * @param ContactInterface|AccountInterface $entity
     *
     * @return bool
     */
    protected function isPhoneAlreadyAssigned(Phone $search, $entity)
    {
        /** @var Phone $phone */
        foreach ($entity->getPhones() as $phone) {
            if ($phone->getPhone() === $search->getPhone()
                && $phone->getPhoneType()->getId() === $search->getPhoneType()->getId()
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if given fax is already assigned to entity.
     *
     * @param Fax $search
     * @param ContactInterface|AccountInterface $entity
     *
     * @return bool
     */
    protected function isFaxAlreadyAssigned(Fax $search, $entity)
    {
        /** @var Phone $phone */
        foreach ($entity->getFaxes() as $fax) {
            if ($fax->getFax() === $search->getFax()
                && $fax->getFaxType()->getId() === $search->getFaxType()->getId()
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if given url is already assigned to entity.
     *
     * @param Url $search
     * @param ContactInterface|AccountInterface $entity
     *
     * @return bool
     */
    protected function isUrlAlreadyAssigned(Url $search, $entity)
    {
        /** @var Phone $phone */
        foreach ($entity->getUrls() as $url) {
            if ($url->getUrl() === $search->getUrl()
                && $url->getUrlType()->getId() === $search->getUrlType()->getId()
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Adds phones to an entity.
     *
     * @param array $data
     * @param Entity $entity
     */
    protected function processPhones($data, $entity)
    {
        // Add phones.
        for ($i = 0, $len = 10; ++$i < $len;) {
            if ($this->checkData('phone' . $i, $data, null, 60)) {
                $phoneIndex = 'phone' . $i;
                $prefix = $phoneIndex . '_';

                $phone = new Phone();
                $phone->setPhone($data[$phoneIndex]);

                // Set label.
                $type = null;
                if ($this->checkData($prefix . 'label', $data)) {
                    $contactLabel = 'phone.' . $this->mapContactLabels($data[$prefix . 'label']);
                    $type = $this->getContactManager()->getPhoneTypeByName($contactLabel);
                }
                if (!$type) {
                    $type = $this->defaultTypes['phoneType'];
                }
                $phone->setPhoneType($type);

                if (!$this->isPhoneAlreadyAssigned($phone, $entity)) {
                    $this->em->persist($phone);
                    $entity->addPhone($phone);
                }
            }
        }
        $this->getContactManager()->setMainPhone($entity);
    }

    /**
     * Adds faxes to an entity.
     *
     * @param array $data
     * @param Entity $entity
     */
    protected function processFaxes($data, $entity)
    {
        // Add faxes.
        for ($i = 0, $len = 10; ++$i < $len;) {
            if ($this->checkData('fax' . $i, $data, null, 60)) {
                $faxIndex = 'fax' . $i;
                $prefix = $faxIndex . '_';

                $fax = new Fax();
                // Set fax.
                $fax->setFax($data[$faxIndex]);

                // Set label.
                $type = null;
                if ($this->checkData($prefix . 'label', $data)) {
                    $contactLabel = 'fax.' . $this->mapContactLabels($data[$prefix . 'label']);
                    $type = $this->getContactManager()->getFaxTypeByName($contactLabel);
                }
                if (!$type) {
                    $type = $this->defaultTypes['faxType'];
                }
                $fax->setFaxType($type);

                if (!$this->isFaxAlreadyAssigned($fax, $entity)) {
                    $this->em->persist($fax);
                    $entity->addFax($fax);
                }
            }
        }
        $this->getContactManager()->setMainFax($entity);
    }

    /**
     * Process tags.
     *
     * @param array $data
     * @param Entity $entity
     */
    protected function processTags($data, $entity)
    {
        $prefix = 'account_';
        if ($entity instanceof ContactInterface) {
            $prefix = 'contact_';
        }
        // Add tags.
        $tagPrefix = $prefix . 'tag';
        $this->checkAndAddTag($tagPrefix, $data, $entity);

        for ($i = 0, $len = 10; ++$i < $len;) {
            $index = $tagPrefix . $i;
            $this->checkAndAddTag($index, $data, $entity);
        }
    }

    /**
     * Checks if tagindex exists in data and adds it to entity's tags.
     *
     * @param string $tagIndex
     * @param array $data
     * @param Entity $entity
     */
    protected function checkAndAddTag($tagIndex, $data, $entity)
    {
        if ($this->checkData($tagIndex, $data, null, 60)) {
            $this->addTag($data[$tagIndex], $entity);
        }
    }

    /**
     * Adds urls to an entity.
     *
     * @param array $data
     * @param Entity $entity
     */
    protected function processUrls($data, $entity)
    {
        // Add urls.
        for ($i = 0, $len = 10; ++$i < $len;) {
            if ($this->checkData('url' . $i, $data, null, 255)) {
                $urlIndex = 'url' . $i;
                $prefix = $urlIndex . '_';

                $url = new Url();
                // Set url.
                $url->setUrl($data[$urlIndex]);

                // Set label.
                $type = null;
                if ($this->checkData($prefix . 'label', $data)) {
                    $contactLabel = 'url.' . $this->mapContactLabels($data[$prefix . 'label']);
                    $type = $this->getContactManager()->getUrlTypeByName($contactLabel);
                }
                if (!$type) {
                    $type = $this->defaultTypes['urlType'];
                }
                $url->setUrlType($type);

                if (!$this->isUrlAlreadyAssigned($url, $entity)) {
                    $this->em->persist($url);
                    $entity->addUrl($url);
                }
            }
        }
        $this->getContactManager()->setMainUrl($entity);
    }

    /**
     * Concats notes and adds it to the entity.
     *
     * @param array $data
     * @param Entity $entity
     * @param string $prefix
     */
    protected function processNotes($data, $entity, $prefix = '')
    {
        // Add note -> only use one note.
        // TODO: use multiple notes, when contact is extended
        $noteValues = array();
        if ($this->checkData($prefix . 'note', $data)) {
            $noteValues[] = $data[$prefix . 'note'];
        }
        for ($i = 0, $len = 10; ++$i < $len;) {
            if ($this->checkData($prefix . 'note' . $i, $data)) {
                $noteValues[] = $data[$prefix . 'note' . $i];
            }
        }
        // Concat all notes to one single note.
        if (sizeof($noteValues) > 0) {
            $noteText = implode("\n", $noteValues);
            $noteText = $this->replaceInvalidNewLineCharacters($noteText);

            $note = new Note();
            $note->setValue($noteText);
            $this->em->persist($note);
            $entity->addNote($note);
        }
    }

    /**
     * Replaces wrong new line characters with real ones (utf8).
     *
     * @param string $text
     *
     * @return string
     *
     */
    protected function replaceInvalidNewLineCharacters($text)
    {
        if (count($this->invalidNewLineCharacters) > 0) {
            foreach ($this->invalidNewLineCharacters as $character) {
                $text = str_replace($character, "\n", $text);
            }
        }

        return $text;
    }

    /**
     * Adds a tag to an account / contact.
     *
     * @param string $tagName
     * @param Entity $entity
     */
    protected function addTag($tagName, $entity)
    {
        $tagName = trim($tagName);
        if (array_key_exists($tagName, $this->tags)) {
            $tag = $this->tags[$tagName];
        } else {
            $tag = new Tag();
            $tag->setName($tagName);
            $this->em->persist($tag);
            $this->tags[$tag->getName()] = $tag;
        }

        // Check if tag is not already assigned.
        if (!$entity->getTags()->contains($tag)) {
            $entity->addTag($tag);
        }
    }

    /**
     * Adds a title to an account / contact.
     *
     * @param string $titleName
     * @param Entity $entity
     */
    protected function addTitle($titleName, $entity)
    {
        $titleName = trim($titleName);
        if (array_key_exists($titleName, $this->titles)) {
            $title = $this->titles[$titleName];
        } else {
            $title = new ContactTitle();
            $title->setTitle($titleName);
            $this->em->persist($title);
            $this->titles[$title->getTitle()] = $title;
        }
        $entity->setTitle($title);
    }

    /**
     * Adds a position to an account / contact.
     *
     * @param string $positionName
     * @param Entity $entity
     */
    protected function addPosition($positionName, $entity)
    {
        $positionName = trim($positionName);

        // Check position name length.
        if (strlen($positionName) > self::MAX_POSITION_LENGTH) {
            $this->debug(
                sprintf(
                    "\nWARNING: Position with '%s' at row %s is longer than %s characters" .
                    "and has therefore been cut.\n",
                    $positionName,
                    $this->rowNumber,
                    self::MAX_POSITION_LENGTH
                )
            );
            $positionName = substr($positionName, 0, self::MAX_POSITION_LENGTH - 1);
        }

        if (array_key_exists(strtolower($positionName), $this->positions)) {
            $position = $this->positions[strtolower($positionName)];
        } else {
            $position = new Position();
            $position->setPosition($positionName);
            $this->em->persist($position);
            $this->positions[strtolower($position->getPosition())] = $position;
        }

        $entity->setPosition($position);
    }

    /**
     * Iterates through data and adds addresses.
     *
     * @param array $data
     * @param Entity $entity
     */
    protected function createAddresses($data, $entity)
    {
        $first = true;
        for ($i = 0, $len = 10; ++$i < $len;) {
            foreach ($data as $key => $value) {
                if (strpos($key, 'address' . $i) !== false) {
                    $address = $this->createAddress($data, $i);
                    // Add address to entity.
                    if ($address !== null) {
                        if (!$this->isAddressAlreadyAssigned($address, $entity)) {
                            $first = $first || $address->getPrimaryAddress();
                            $this->getManager($entity)->addAddress($entity, $address, $first);
                        }
                    }
                    break;
                }
            }
        }
    }

    /**
     * Creates an address entity based on passed data.
     *
     * @param array $data
     * @param int $id index of the address in data array (e.g. 1 => address1_street)
     *
     * @throws EntityNotFoundException
     *
     * @return null|Address
     */
    protected function createAddress($data, $id = 1)
    {
        // Set address.
        $address = new Address();
        $addAddress = false;
        $prefix = 'address' . $id . '_';

        // Street.
        if ($this->checkData($prefix . 'street', $data)) {
            $street = $data[$prefix . 'street'];

            // Separate street and number.
            if ($this->options['streetNumberSplit']) {
                preg_match('/(*UTF8)([^\d]+)\s?(.+)/iu', $street, $result); // UTF8 is to ensure correct utf8 encoding

                // Check if number is given, else do not apply preg match.
                if (array_key_exists(2, $result) && $this->startsWithNumber($result[2])) {
                    $number = trim($result[2]);
                    $street = trim($result[1]);
                }
            }
            if (!$street) {
                $street = '';
            }
            $address->setStreet($street);
            $addAddress = true;
        }
        // Number
        if (isset($number) || $this->checkData($prefix . 'number', $data)) {
            $number = isset($number) ? $number : $data[$prefix . 'number'];
            if (!$number) {
                $number = '';
            }
            $address->setNumber($number);
        }
        // Title
        $addressTitle = '';
        if ($this->checkData($prefix . 'title', $data)) {
            $addressTitle = $data[$prefix . 'title'];
            $addAddress = true;
        }
        $address->setTitle(trim($addressTitle));
        // Zip
        if ($this->checkData($prefix . 'zip', $data)) {
            $address->setZip($data[$prefix . 'zip']);
            $addAddress = true;
        }
        // City
        if ($this->checkData($prefix . 'city', $data)) {
            $address->setCity($data[$prefix . 'city']);
            $addAddress = true;
        }
        // State
        if ($this->checkData($prefix . 'state', $data)) {
            $address->setState($data[$prefix . 'state']);
            $addAddress = true;
        }
        // Extension
        if ($this->checkData($prefix . 'extension', $data)) {
            $address->setAddition($data[$prefix . 'extension']);
            $addAddress = true;
        }

        // Define if this is a normal address or just a postbox address
        $isNormalAddress = $addAddress;

        // Postbox
        if ($this->checkData($prefix . 'postbox', $data)) {
            $address->setPostboxNumber($data[$prefix . 'postbox']);
            $addAddress = true;
        }
        if ($this->checkData($prefix . 'postbox_zip', $data)) {
            $address->setPostboxPostcode($data[$prefix . 'postbox_zip']);
            $addAddress = true;
        }
        if ($this->checkData($prefix . 'postbox_city', $data)) {
            $address->setPostboxCity($data[$prefix . 'postbox_city']);
            $addAddress = true;
        }
        // Note
        if ($this->checkData($prefix . 'note', $data)) {
            $address->setNote(
                $this->replaceInvalidNewLineCharacters($data[$prefix . 'note'])
            );
            $addAddress = true;
        }
        // Billing address
        if ($this->checkData($prefix . 'isbilling', $data)) {
            $address->setBillingAddress(
                $this->getBoolValue($data[$prefix . 'isbilling'])
            );
        }
        // Delivery address
        if ($this->checkData($prefix . 'isdelivery', $data)) {
            $address->setDeliveryAddress(
                $this->getBoolValue($data[$prefix . 'isdelivery'])
            );
        }
        // Primary address
        if ($this->checkData($prefix . 'isprimary', $data)) {
            $address->setPrimaryAddress(
                $this->getBoolValue($data[$prefix . 'isprimary'])
            );
        }
        // Country
        if ($this->checkData($prefix . 'country', $data)) {
            $country = $this->em->getRepository($this->countryEntityName)->findOneByCode(
                $this->mapCountryCode($data[$prefix . 'country'])
            );

            if (!$country) {
                throw new EntityNotFoundException('Country', $data[$prefix . 'country']);
            }

            $address->setCountry($country);
            $addAddress = $addAddress && true;
        } else {
            if ($addAddress && $isNormalAddress) {
                $this->debug(sprintf("\nWarning: No country defined at line %s\n", $this->rowNumber));
            }
            $addAddress = false;
        }

        // Only add address if part of it is defined
        if ($addAddress) {
            $addressType = null;
            if ($this->checkData($prefix . 'label', $data)) {
                $contactLabel = 'address.' . $this->mapContactLabels($data[$prefix . 'label']);
                $addressType = $this->getContactManager()->getAddressTypeByName($contactLabel);
            }
            if (!$addressType) {
                $addressType = $this->defaultTypes['addressType'];
            }
            $address->setAddressType($addressType);

            $this->em->persist($address);

            return $address;
        }

        return null;
    }

    /**
     * Returns true if $value is true or y or j or 1.
     *
     * @param string $value
     *
     * @return bool
     */
    protected function getBoolValue($value)
    {
        if ($value == true ||
            strtolower($value) === 'y' ||
            strtolower($value) === 'j' ||
            $value == '1'
        ) {
            return true;
        }

        return false;
    }

    /**
     * Gets financial information and adds it.
     *
     * @param array $data
     * @param Entity $entity
     *
     * @throws ImportException
     */
    protected function addBankAccounts($data, $entity)
    {
        for ($i = 0, $len = 10; ++$i < $len;) {
            $bankIndex = 'bank' . $i;
            $prefix = $bankIndex . '_';

            // If iban is set, then add bank account.
            if ($this->checkData($prefix . 'iban', $data)) {

                $bank = new BankAccount();
                if ($this->checkData($prefix . 'iban', $data)) {
                    $bank->setIban($data[$prefix . 'iban']);
                } else {
                    throw new ImportException('no Iban provided for entity with id ' . $entity->getId());
                }

                if ($this->checkData($prefix . 'bic', $data)) {
                    $bank->setBic($data[$prefix . 'bic']);
                }
                if ($this->checkData($bankIndex, $data)) {
                    $bank->setBankName($data[$bankIndex]);
                }
                // Set bank to public.
                if ($this->checkData($prefix . 'public', $data, 'bool')) {
                    $bank->setPublic($data[$prefix . 'public']);
                } else {
                    $bank->setPublic(false);
                }

                $this->em->persist($bank);
                $entity->addBankAccount($bank);
            }

            // Create comments for old bank addresses.
            if ($this->checkData($prefix . 'blz', $data)) {
                $noteTxt = 'Old Bank Account: ';
                $noteTxt .= 'BLZ: ';
                $noteTxt .= $data[$prefix . 'blz'];

                if ($this->checkData($prefix . 'number', $data)) {
                    $noteTxt .= '; Account-Number: ';
                    $noteTxt .= $data[$prefix . 'number'];
                }
                if ($this->checkData($bankIndex, $data)) {
                    $noteTxt .= '; Bank-Name: ';
                    $noteTxt .= $data[$bankIndex];
                }

                $this->appendToNote($entity, $noteTxt);
            }
        }
    }

    /**
     * Function either appends a text to the existing note, or creates a new one.
     *
     * @param Entity $entity The entity containing the note
     * @param string $text Text to append
     */
    protected function appendToNote($entity, $text)
    {
        $noteText = '';
        if (sizeof($notes = $entity->getNotes()) > 0) {
            $note = $notes[0];
            $noteText = $note->getValue() . "\n";
        } else {
            $note = new Note();
            $this->em->persist($note);
            $entity->addNote($note);
        }
        $text = $this->replaceInvalidNewLineCharacters($text);
        $noteText .= $text;
        $note->setValue($noteText);
    }

    /**
     * Creates an contact for given row data.
     *
     * @param array $data
     * @param int $row
     *
     * @return ContactInterface
     */
    protected function createContact(array $data, $row)
    {
        try {
            // Check if contact already exists.
            $contact = $this->getContactByData($data);

            // Or create a new one.
            if (!$contact) {
                $contact = $this->contactRepository->createNew();
                $this->em->persist($contact);
            }

            // Set data on contact.
            $this->setContactData($data, $contact);
            // Create account relation.
            $this->createAccountContactRelations($data, $contact, $row);

            return $contact;
        } catch (NonUniqueResultException $nur) {
            $this->debug(sprintf("\nNon unique result for contact at row %d \n", $this->rowNumber));
        }

        return null;
    }

    /**
     * Sets data to Contact entity by given data array.
     *
     * @param array $data
     * @param ContactInterface $contact
     */
    protected function setContactData(array $data, ContactInterface $contact)
    {
        if ($this->checkData('contact_firstname', $data)) {
            $contact->setFirstName($data['contact_firstname']);
        } else {
            // TODO: dont accept this
            $contact->setFirstName('');
        }
        if ($this->checkData('contact_lastname', $data)) {
            $contact->setLastName($data['contact_lastname']);
        } else {
            // TODO: dont accept this
            $contact->setLastName('');
        }

        if ($this->checkData('contact_title', $data)) {
            $this->addTitle($data['contact_title'], $contact);
        }

        if ($this->checkData('contact_form_of_address', $data)) {
            $contact->setFormOfAddress($this->mapFormOfAddress($data['contact_form_of_address']));
        }

        if ($this->checkData('contact_salutation', $data)) {
            $contact->setSalutation($data['contact_salutation']);
        }

        if ($this->checkData('contact_birthday', $data)) {
            $birthday = $this->createDateFromString($data['contact_birthday']);
            $contact->setBirthday($birthday);
        }

        // Add address if set.
        $this->createAddresses($data, $contact);

        // Process emails, phones, faxes, urls and notes.
        $this->processTags($data, $contact);
        $this->processEmails($data, $contact);
        $this->processPhones($data, $contact);
        $this->processFaxes($data, $contact);
        $this->processUrls($data, $contact);
        $this->processNotes($data, $contact, 'contact_');
    }

    /**
     * Checks if a main account-contact relation exists.
     *
     * @param Entity $entity
     *
     * @return bool
     */
    private function mainRelationExists($entity)
    {
        return $entity->getAccountContacts()->exists(
            function ($index, $entity) {
                return $entity->getMain() === true;
            }
        );
    }

    /**
     * Either creates a single contact relation (contact_account) or multiple relations (contact_account1..n).
     *
     * @param array $data
     * @param ContactInterface $contact
     * @param int $row
     */
    protected function createAccountContactRelations($data, $contact, $row)
    {
        $index = 'contact_account';

        // Check index without number.
        $this->checkAndCreateAccountContactRelation($index, $data, $contact, $row);

        // Then check with postfixed numbers.
        for ($i = 0, $len = 10; ++$i < $len;) {
            $this->checkAndCreateAccountContactRelation($index . $i, $data, $contact, $row);
        }
    }

    /**
     * Checks if contact_account with given index is set in data
     * and creates an account contact relation.
     *
     * @param string $index
     * @param array $data
     * @param ContactInterface $contact
     * @param int $row
     */
    protected function checkAndCreateAccountContactRelation($index, $data, $contact, $row)
    {
        if ($this->checkData($index, $data)) {
            $this->createAccountContactRelation($data, $contact, $row, $index);
        }
    }

    /**
     * Adds an AccountContact relation if not existent.
     *
     * @param array $data
     * @param ContactInterface $contact
     * @param int $row
     * @param string $index - account-index in data array
     */
    protected function createAccountContactRelation($data, $contact, $row, $index)
    {
        $account = $this->getAccountByKey($data[$index]);

        if (!$account) {
            $this->debug(
                sprintf(
                    "%sCould not assign contact at row %d to %s. (account could not be found)%s",
                    PHP_EOL,
                    $row,
                    $data[$index],
                    PHP_EOL
                )
            );
        } else {
            // Check if relation already exists.
            $accountContact = null;
            if (!$this->em->getUnitOfWork()->isScheduledForInsert($contact)) {
                $accountContact = $this->em
                    ->getRepository($this->accountContactEntityName)
                    ->findOneBy(
                        array(
                            'account' => $account,
                            'contact' => $contact
                        )
                    );
            }

            // If relation already exists - do not continue.
            if ($accountContact) {
                return;
            }

            // Create new account contact relation.
            $accountContact = new AccountContact();
            $accountContact->setContact($contact);
            $accountContact->setAccount($account);
            $contact->addAccountContact($accountContact);
            $account->addAccountContact($accountContact);
            $this->em->persist($accountContact);

            // Check if relation should be set to main.
            $main = false;
            if ($this->checkData('contact_account_is_main', $data)) {
                $main = $this->getBoolValue($data['contact_account_is_main']);
            } elseif (!$this->mainRelationExists($contact)) {
                // Check if main relation exists.
                $main = true;
            }
            $accountContact->setMain($main);

            // Set position.
            if ($this->checkData('contact_position', $data)) {
                $this->addPosition($data['contact_position'], $accountContact);
            }
        }
    }

    /**
     * Returns a contact based on data array if it already exists in DB.
     *
     * @param array $data
     *
     * @return ContactInterface
     */
    protected function getContactByData($data)
    {
        $criteria = array();
        $email = null;
        $phone = null;

        if ($this->options['importContactByIds'] == true && $this->checkData('contact_id', $data)) {
            $criteria['id'] = $data['contact_id'];
        } else {
            // Check if contacts already exists.
            if (array_search('firstName', $this->options['contactComparisonCriteria']) !== false) {
                if ($this->checkData('contact_firstname', $data)) {
                    $criteria['firstName'] = $data['contact_firstname'];
                }
            }
            if (array_search('lastName', $this->options['contactComparisonCriteria']) !== false) {
                if ($this->checkData('contact_lastname', $data)) {
                    $criteria['lastName'] = $data['contact_lastname'];
                }
            }
            if (array_search('email', $this->options['contactComparisonCriteria']) !== false) {
                $email = $this->getFirstOf('email', $data);
            }
            if (array_search('phone', $this->options['contactComparisonCriteria']) !== false) {
                $phone = $this->getFirstOf('phone', $data);
            }
        }

        $repo = $this->contactRepository;
        $contact = $repo->findByCriteriaEmailAndPhone($criteria, $email, $phone);

        return $contact;
    }

    /**
     * Checks data for validity.
     */
    protected function checkData($index, $data, $type = null, $maxLength = null)
    {
        $isDataSet = array_key_exists($index, $data) && $data[$index] !== '';
        if ($isDataSet) {
            if ($type !== null) {
                // TODO check for types
                if ($type === 'bool'
                    && $data[$index] != 'true'
                    && $data[$index] != 'false'
                    && $data[$index] != '1'
                    && $data[$index] != '0'
                ) {
                    throw new \InvalidArgumentException($data[$index] . ' is not a boolean!');
                }
            }
            if ($maxLength !== null && intval($maxLength) && strlen($data[$index]) > $maxLength) {
                throw new \InvalidArgumentException($data[$index] . ' exceeds max length of ' . $index);
            }
        }

        return $isDataSet;
    }

    /**
     * Creates relation between parent and account.
     */
    protected function createAccountParentRelation($data, $row)
    {
        // if account has parent
        if ($this->checkData('account_parent_id', $data)) {
            // get account
            $externalId = $this->getExternalId($data, $row);
            /** @var AccountInterface $account */
            $account = $this->getAccountByKey($externalId);

            if (!$account) {
                throw new \Exception(sprintf('account with id %s could not be found.', $externalId));
            }
            // get parent account
            $parent = $this->getAccountByKey($data['account_parent_id']);
            $account->setParent($parent);
            $parent->addChildren($account);
        }
    }

    /**
     * Truncate table for account and contact.
     */
    protected function clearDatabase()
    {
        $this->clearTable($this->accountRepository->getClassName());
        $this->clearTable($this->contactRepository->getClassName());
    }

    /**
     * Truncate one single table for given entity name.
     *
     * @param string $entityName name of entity
     */
    protected function clearTable($entityName)
    {
        $connection = $this->em->getConnection();
        $platform = $connection->getDatabasePlatform();

        $connection->executeQuery('SET FOREIGN_KEY_CHECKS = 0;');
        $truncateSql = $platform->getTruncateTableSQL($entityName);
        $connection->executeUpdate($truncateSql);
        $connection->executeQuery('SET FOREIGN_KEY_CHECKS = 1;');
    }

    /**
     * Returns an associative array of data mapped by configuration.
     *
     * @param array $data data of a single csv row
     * @param array $headerData header data of csv containing column names
     *
     * @return array
     */
    protected function mapRowToAssociativeArray($data, $headerData)
    {
        $associativeData = array();
        foreach ($data as $index => $value) {
            if ($index >= sizeof($headerData)) {
                break;
            }
            if (empty($value) && $value != "0") {
                continue;
            }
            // search index in mapping config
            if (sizeof($resultArray = array_keys($this->columnMappings, $headerData[$index])) > 0) {
                foreach ($resultArray as $key) {
                    $associativeData[$key] = $value;
                }
            } else {
                $associativeData[($headerData[$index])] = $value;
            }
        }

        return array_merge($this->defaults, $associativeData);
    }

    /**
     * Load tags from database.
     */
    protected function loadTags()
    {
        $tags = $this->em->getRepository($this->tagEntityName)->findAll();
        /** @var Tag $tag */
        foreach ($tags as $tag) {
            $this->tags[$tag->getName()] = $tag;
        }
    }

    /**
     * Load titles from database.
     */
    protected function loadTitles()
    {
        $titles = $this->em->getRepository($this->titleEntityName)->findAll();
        /** @var Title $title */
        foreach ($titles as $title) {
            $this->titles[$title->getTitle()] = $title;
        }
    }

    /**
     * Load positions from Database.
     */
    protected function loadPositions()
    {
        $positions = $this->em->getRepository($this->positionEntityName)->findAll();
        /** @var Position $position */
        foreach ($positions as $position) {
            $this->positions[strtolower($position->getPosition())] = $position;
        }
    }

    /**
     * @param string $countryCode
     *
     * @return mixed|string
     */
    protected function mapCountryCode($countryCode)
    {
        if (array_key_exists($countryCode, $this->countryMappings)) {
            return $this->countryMappings[$countryCode];
        }

        return mb_strtoupper($countryCode);
    }

    /**
     * Returns form of addresses id, if defined.
     *
     * @param string $formOfAddress
     *
     * @return int|null
     */
    protected function mapFormOfAddress($formOfAddress)
    {
        return $this->mapReverseByConfigId($formOfAddress, $this->formOfAddressMappings, $this->configFormOfAddress);
    }

    /**
     * @param string $typeString
     *
     * @return int
     */
    protected function mapAccountType($typeString)
    {
        if (array_key_exists($typeString, $this->accountTypeMappings)) {
            return $this->accountTypeMappings[$typeString];
        } else {
            return Account::TYPE_BASIC;
        }
    }

    /**
     * @param string $typeString
     *
     * @return int
     */
    protected function mapContactLabels($typeString)
    {
        if (array_key_exists($typeString, $this->contactLabelMappings)) {
            return $this->contactLabelMappings[$typeString];
        } else {
            return $typeString;
        }
    }

    /**
     * @param array $formOfAddressMappings
     */
    public function setFormOfAddressMappings($formOfAddressMappings)
    {
        $this->formOfAddressMappings = $formOfAddressMappings;
    }

    /**
     * @param string $contactFile
     */
    public function setContactFile($contactFile)
    {
        $this->contactFile = $contactFile;
    }

    /**
     * @return string
     */
    public function getContactFile()
    {
        return $this->contactFile;
    }

    /**
     * @param string $accountFile
     */
    public function setAccountFile($accountFile)
    {
        $this->accountFile = $accountFile;
    }

    /**
     * @return string
     */
    public function getAccountFile()
    {
        return $this->accountFile;
    }

    /**
     * @param int $limit
     */
    public function setLimit($limit)
    {
        $this->limit = $limit;
    }

    /**
     * @return int
     */
    public function getLimit()
    {
        return $this->limit;
    }

    /**
     * @param array $columnMappings
     */
    public function setColumnMappings($columnMappings)
    {
        $this->columnMappings = $columnMappings;
    }

    /**
     * @return array
     */
    public function getColumnMappings()
    {
        return $this->columnMappings;
    }

    /**
     * @param int $key
     *
     * @return AccountInterface|null
     */
    public function getAccountByKey($key)
    {
        return $this->accountRepository->findOneBy(array('externalId' => $key));
    }

    /**
     * @param array $countryMappings
     */
    public function setCountryMappings($countryMappings)
    {
        $this->countryMappings = $countryMappings;
    }

    /**
     * @return array
     */
    public function getCountryMappings()
    {
        return $this->countryMappings;
    }

    /**
     * @param array $idMappings
     */
    public function setIdMappings($idMappings)
    {
        $this->idMappings = $idMappings;
    }

    /**
     * @return array
     */
    public function getIdMappings()
    {
        return $this->idMappings;
    }

    /**
     * @param array $accountTypeMappings
     */
    public function setAccountTypeMappings($accountTypeMappings)
    {
        $this->accountTypeMappings = $accountTypeMappings;
    }

    /**
     * @return array
     */
    public function getAccountTypeMappings()
    {
        return $this->accountTypeMappings;
    }

    /**
     * @param array $options
     */
    public function setOptions($options)
    {
        $this->options = array_merge($this->options, $options);
    }

    /**
     * @param string $mappingsFile
     */
    public function setMappingsFile($mappingsFile)
    {
        $this->mappingsFile = $mappingsFile;
    }

    /**
     * TODO outsource this into a service! also used in template controller
     * Returns the default values for the dropdowns
     *
     * @return array
     */
    protected function getDefaults()
    {
        $config = $this->configDefaults;
        $defaults = array();

        $emailTypeEntity = 'SuluContactBundle:EmailType';
        $defaults['emailType'] = $this->em
            ->getRepository($emailTypeEntity)
            ->find($config['emailType']);

        $phoneTypeEntity = 'SuluContactBundle:PhoneType';
        $defaults['phoneType'] = $this->em
            ->getRepository($phoneTypeEntity)
            ->find($config['phoneType']);

        $addressTypeEntity = 'SuluContactBundle:AddressType';
        $defaults['addressType'] = $this->em
            ->getRepository($addressTypeEntity)
            ->find($config['addressType']);

        $urlTypeEntity = 'SuluContactBundle:UrlType';
        $defaults['urlType'] = $this->em
            ->getRepository($urlTypeEntity)
            ->find($config['urlType']);

        $faxTypeEntity = 'SuluContactBundle:FaxType';
        $defaults['faxType'] = $this->em
            ->getRepository($faxTypeEntity)
            ->find($config['faxType']);

        $countryEntity = 'SuluContactBundle:Country';
        $defaults['country'] = $this->em
            ->getRepository($countryEntity)
            ->find($config['country']);

        return $defaults;
    }

    /**
     * Prints messages if debug is set to true.
     *
     * @param string $message
     */
    protected function debug($message, $addToLog = true)
    {
        if ($addToLog) {
            $this->log[] = $message;
        }
        if (self::DEBUG) {
            print($message);
        }
    }

    /**
     * Creates a logfile in import-files folder.
     */
    public function createLogFile()
    {
        $root = 'import-files/logs/contactimport/';
        $timestamp = time();
        $file = fopen($root . 'log-' . $timestamp . '.txt', 'w');
        fwrite($file, implode("\n", $this->log));
        fclose($file);
    }

    /**
     * Maps a certain index to a mappings array and returns it's index as defined in config array.
     * Mapping is defined as mappingindex => $index.
     *
     * @param int|string $index
     * @param array $mappings
     * @param array $config
     *
     * @return string
     */
    protected function mapByConfigId($index, $mappings, $config)
    {
        if ($mappingIndex = array_search($index, $mappings)) {
            if (array_key_exists($mappingIndex, $config)) {
                return $config[$mappingIndex]['id'];
            }

            return $mappingIndex;
        } else {
            return $index;
        }
    }

    /**
     * Maps a certain index to a mappings array and returns it's index as defined in config array.
     *
     * @param int|string $index
     * @param array $mappings
     * @param array $config
     *
     * @return string
     */
    protected function mapReverseByConfigId($index, $mappings, $config)
    {
        if (array_key_exists($index, $mappings)) {
            $mappingIndex = $mappings[$index];
            if (array_key_exists($mappingIndex, $config)) {
                return $config[$mappingIndex]['id'];
            }

            return $mappingIndex;
        } else {
            return $index;
        }
    }

    /**
     * Gets the external id of an account by providing the dataset.
     *
     * @param array $data
     * @param int $row
     *
     * @throws \Exception
     *
     * @return string
     */
    protected function getExternalId($data, $row)
    {
        if (array_key_exists('account_id', $this->idMappings)) {
            if (!array_key_exists($this->idMappings['account_id'], $data)) {
                throw new \Exception(
                    'No key ' + $this->idMappings['account_id'] + ' found in column definition of accounts file'
                );
            }
            $externalId = $data[$this->idMappings['account_id']];
        } else {
            $externalId = $this->accountExternalIds[$row - 1];
        }

        return $externalId;
    }

    /**
     * @param ContactInterface|AccountInterface $entity
     *
     * @return AbstractContactManager
     */
    protected function getManager($entity)
    {
        if ($entity instanceof ContactInterface) {
            return $this->getContactManager();
        } else {
            return $this->getAccountManager();
        }
    }

    /**
     * @return AbstractContactManager
     */
    protected function getContactManager()
    {
        return $this->contactManager;
    }

    /**
     * @return AbstractContactManager
     */
    protected function getAccountManager()
    {
        return $this->accountManager;
    }

    /**
     * Checks if a string starts with a number.
     *
     * @param string $numberString
     *
     * @return bool
     */
    protected function startsWithNumber($numberString)
    {
        if (preg_match('/^[0-9]+.*/', $numberString)) {
            return true;
        }

        return false;
    }

    /**
     * Tries to parse string into date-time.
     *
     * @param $dateString
     *
     * @return DateTime|null
     */
    protected function createDateFromString($dateString)
    {
        try {
            return new DateTime($dateString);
        } catch (\Exception $e) {
            return null;
        }
    }
}
