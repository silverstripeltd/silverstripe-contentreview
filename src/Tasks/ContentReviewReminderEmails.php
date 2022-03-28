<?php

namespace SilverStripe\ContentReview\Tasks;

use Page;
use Psr\Log\LoggerInterface;
use SilverStripe\ContentReview\Compatibility\ContentReviewCompatability;
use SilverStripe\ContentReview\Extensions\SiteTreeContentReview;
use SilverStripe\Control\Email\Email;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\FieldType\DBDate;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\SS_List;
use SilverStripe\Security\Member;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\View\ArrayData;
use SilverStripe\View\SSViewer;

/**
 * Daily task that compares the Next review date of content pages and sends an email to the owners to remind them
 * that the content is nearing overdue for their review.
 */
class ContentReviewReminderEmails extends BuildTask
{
    /**
     * @param HTTPRequest $request
     */
    public function run($request)
    {
        $compatibility = ContentReviewCompatability::start();

        // First grab all the pages with a custom setting
        $pages = Page::get()
            ->filter('NextReviewDate:GreaterThan', DBDatetime::now()->URLDate());

        $upcomingPagesForReview = $this->getOverduePagesForOwners($pages);

        // Lets send one email to one owner with all the pages in there instead of no of pages
        // of emails.
        foreach ($upcomingPagesForReview as $memberID => $pages) {
            $this->notifyOwner($memberID, $pages);
        }

        ContentReviewCompatability::done($compatibility);
    }

    /**
     * @param SS_List $pages
     *
     * @return array
     */
    protected function getOverduePagesForOwners(SS_List $pages)
    {
        $overduePages = [];
        $reminderIntervals = SiteTreeContentReview::get_reminder_intervals();

        foreach ($pages as $page) {
            // get the owner NextReviewDate in 'days', so we can compare to our intervals
            $upcomingForReviewDateInDays = $this->getUpcomingForReviewDateInDays($page->NextReviewDate);
            $reminderIntervals = array_values($reminderIntervals);

            if ( in_array($upcomingForReviewDateInDays, $reminderIntervals)) {
                $options = $page->getOptions();

                if ($options) {
                    foreach ($options->ContentReviewOwners() as $owner) {
                        if (!isset($overduePages[$owner->ID])) {
                            $overduePages[$owner->ID] = ArrayList::create();
                        }

                        // add our overdue NextReviewDate in days form
                        $page->UpcomingReviewdateInDays = $upcomingForReviewDateInDays;
                        $overduePages[$owner->ID]->push($page);
                    }
                }
            }
        }

        return $overduePages;
    }

    /**
     * @param int           $ownerID
     * @param array|SS_List $pages
     */
    protected function notifyOwner($ownerID, SS_List $pages)
    {
        // Prepare variables
        $siteConfig = SiteConfig::current_site_config();
        $owner = Member::get()->byID($ownerID);
        $templateVariables = $this->getTemplateVariables($owner, $siteConfig, $pages);

        // Build over due email
        $email = Email::create();
        $email->setTo($owner->Email);
        $email->setFrom($siteConfig->ReviewFrom);
        $email->setSubject($siteConfig->ReminderSubject);

        // Get user-editable body
        $body = $this->getEmailBody($siteConfig, $templateVariables);

        // Populate mail body with fixed template
        $email->setHTMLTemplate($siteConfig->config()->get('content_review_reminder_template'));
        $email->setData(
            array_merge(
                $templateVariables,
                [
                    'EmailBody' => $body,
                    'Recipient' => $owner,
                    'Pages' => $pages,
                ]
            )
        );
        $email->send();
    }

    /**
     * Get string value of HTML body with all variable evaluated.
     *
     * @param SiteConfig $config
     * @param array List of safe template variables to expose to this template
     *
     * @return HTMLText
     */
    protected function getEmailBody($config, $variables)
    {
        $template = SSViewer::fromString($config->ReminderBody);
        $value = $template->process(ArrayData::create($variables));

        // Cast to HTML
        return DBField::create_field('HTMLText', (string) $value);
    }

    /**
     * Gets list of safe template variables and their values which can be used
     * in both the static and editable templates.
     *
     * {@see ContentReviewAdminHelp.ss}
     *
     * @param Member     $recipient
     * @param SiteConfig $config
     * @param SS_List    $pages
     *
     * @return array
     */
    protected function getTemplateVariables($recipient, $config, $pages)
    {
        return [
            'ReminderSubject' => $config->Remindersubject,
            'PagesCount' => $pages->count(),
            'FromEmail' => $config->ReviewFrom,
            'ToFirstName' => $recipient->FirstName,
            'ToSurname' => $recipient->Surname,
            'ToEmail' => $recipient->Email,
        ];
    }

    /**
     * Helper method that compares a page owner `NextReviewDate` to {@see DBDatetime::now()}
     * and returns a formatted in 'days' value.
     * This return is used to validate to the configurable reminder interval values.
     *
     * {@see SiteTreeContentReview::$reminder_intervals}
     *
     * @param string $pageOwnerNextReviewDate
     * @return string
     */
    protected function getUpcomingForReviewDateInDays(string $pageOwnerNextReviewDate): string
    {
        $nextReviewDateInDays = DBDate::create()->setValue($pageOwnerNextReviewDate);
        return $nextReviewDateInDays->TimeDiffIn('days');
    }
}
