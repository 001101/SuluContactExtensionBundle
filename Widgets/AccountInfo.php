<?php
/*
  * This file is part of the Sulu CMS.
  *
  * (c) MASSIVE ART WebServices GmbH
  *
  * This source file is subject to the MIT license that is bundled
  * with this source code in the file LICENSE.
  */

namespace Sulu\Bundle\ContactExtensionBundle\Widgets;

use Sulu\Bundle\AdminBundle\Widgets\WidgetInterface;
use Doctrine\ORM\EntityManager;
use Sulu\Bundle\ContactBundle\Entity\AccountInterface;
use Sulu\Bundle\ContactBundle\Entity\Address;
use Sulu\Bundle\AdminBundle\Widgets\WidgetException;
use Sulu\Bundle\AdminBundle\Widgets\WidgetParameterException;
use Sulu\Bundle\AdminBundle\Widgets\WidgetEntityNotFoundException;

/**
 * Widget for displaying account info
 */
class AccountInfo implements WidgetInterface
{
    protected $em;

    protected $widgetName = 'AccountInfo';
    protected $accountEntityName;

    public function __construct(EntityManager $em, $accountEntityName)
    {
        $this->em = $em;
        $this->accountEntityName = $accountEntityName;
    }

    /**
     * return name of widget
     *
     * @return string
     */
    public function getName()
    {
        return 'account-info';
    }

    /**
     * returns template name of widget
     *
     * @return string
     */
    public function getTemplate()
    {
        return 'SuluContactExtensionBundle:Widgets:account.info.html.twig';
    }

    /**
     * returns data to render template
     *
     * @param array $options
     * @throws WidgetException
     * @return array
     */
    public function getData($options)
    {
        if (!empty($options) &&
            array_key_exists('account', $options) &&
            !empty($options['account'])
        ) {
            $id = $options['account'];
            $account = $this->em->getRepository($this->accountEntityName)->find($id);

            if (!$account) {
                throw new WidgetEntityNotFoundException(
                    'Entity ' . $this->accountEntityName . ' with id ' . $id . ' not found!',
                    $this->widgetName,
                    $id
                );
            }

            return $this->parseAccountForListSidebar($account);
        } else {
            throw new WidgetParameterException(
                'Required parameter account not found or empty!',
                $this->widgetName,
                'account'
            );
        }
    }

    /**
     * Returns the data neede for the account list-sidebar
     *
     * @param AccountInterface $account
     * @return array
     */
    protected function parseAccountForListSidebar(AccountInterface $account)
    {
        $data = [];

        $data['id'] = $account->getId();
        $data['name'] = $account->getName();

        /* @var Address $accountAddress */
        $accountAddress = $account->getMainAddress();

        if ($accountAddress) {
            $data['address']['street'] = $accountAddress->getStreet();
            $data['address']['number'] = $accountAddress->getNumber();
            $data['address']['zip'] = $accountAddress->getZip();
            $data['address']['city'] = $accountAddress->getCity();
            $data['address']['country'] = $accountAddress->getCountry(
            )->getName();
        }

        $data['phone'] = $account->getMainPhone();
        $data['fax'] = $account->getMainFax();

        $data['email'] = $account->getMainEmail();
        $data['url'] = $account->getMainUrl();

        if ($account->getTermsOfPayment()) {
            $data['termsOfPayment'] = $account->getTermsOfPayment()->getTerms();
        }
        if ($account->getTermsOfDelivery()) {
            $data['termsOfDelivery'] = $account->getTermsOfDelivery()->getTerms();
        }

        return $data;
    }
}
