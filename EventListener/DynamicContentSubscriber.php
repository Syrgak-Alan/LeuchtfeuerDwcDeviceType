<?php

namespace MauticPlugin\LeuchtfeuerDwcDeviceTypeBundle\EventListener;

use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\DynamicContentBundle\DynamicContentEvents;
use Mautic\DynamicContentBundle\Event\ContactFiltersEvaluateEvent;
use Mautic\DynamicContentBundle\Helper\DynamicContentHelper;
use Mautic\LeadBundle\Entity\LeadDevice;
use Mautic\LeadBundle\Model\DeviceModel;
use Mautic\LeadBundle\Tracker\ContactTracker;
use Mautic\PageBundle\Event\PageDisplayEvent;
use Mautic\PageBundle\PageEvents;
use MauticPlugin\LeuchtfeuerDwcDeviceTypeBundle\Services\DynamicContentService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class DynamicContentSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private DeviceModel $deviceModel,
        private DynamicContentService $dynamicContentService,
        private CorePermissions $security,
        private DynamicContentHelper $dynamicContentHelper,
        private ContactTracker $contactTracker,
    )
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            DynamicContentEvents::ON_CONTACTS_FILTER_EVALUATE => ['onContactFiltersEvaluate', 0],
            PageEvents::PAGE_ON_DISPLAY             => ['decodeTokens', 254],
        ];
    }

    public function onContactFiltersEvaluate(ContactFiltersEvaluateEvent $event): void
    {
        $filters = $event->getFilters();
        $contact = $event->getContact();
        /** @var LeadDevice $leadDevice */
        $leadDevice = $this->deviceModel->getEntity($contact->getId());
        if (empty($leadDevice)) {
            return;
        }


        $deviceType = $leadDevice->getDevice();
        foreach ($filters as $filter) {
            if ('device_type' === $filter['type']) {
                if ($deviceType) {
                    $event->setIsEvaluated(true);
                    $event->setIsMatched(in_array($deviceType, $filter['filter']));
                }
            }
        }
    }

    public function decodeTokens(PageDisplayEvent $event): void
    {
        $lead = $this->security->isAnonymous() ? $this->contactTracker->getContact() : null;
        if (!$lead) {
            return;
        }

        $content = $event->getContent();
        if (empty($content)) {
            return;
        }

        $tokens    = $this->dynamicContentHelper->findDwcTokens($content, $lead);
        $leadArray = $this->dynamicContentHelper->convertLeadToArray($lead);
        $result    = [];
        foreach ($tokens as $token => $dwc) {
            $result[$token] = '';
            $eventFilters = new ContactFiltersEvaluateEvent($dwc['filters'], $lead);
            if ($this->onContactFiltersEvaluate($eventFilters)) {
                $result[$token] = $dwc['content'];
            }
        }
        $content = str_replace(array_keys($result), array_values($result), $content);

        // replace slots
        $dom = new \DOMDocument('1.0', 'utf-8');
        $dom->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'), LIBXML_NOERROR);
        $xpath = new \DOMXPath($dom);

        $divContent = $xpath->query('//*[@data-slot="dwc"]');
        for ($i = 0; $i < $divContent->length; ++$i) {
            $slot = $divContent->item($i);
            if (!$slotName = $slot->getAttribute('data-param-slot-name')) {
                continue;
            }

            if (!$slotContent = $this->dynamicContentHelper->getDynamicContentForLead($slotName, $lead)) {
                continue;
            }

            $newnode = $dom->createDocumentFragment();
            $newnode->appendXML('<![CDATA['.mb_convert_encoding($slotContent, 'HTML-ENTITIES', 'UTF-8').']]>');
            $slot->parentNode->replaceChild($newnode, $slot);
        }

        $content = $dom->saveHTML();

        $event->setContent($content);
    }
}
