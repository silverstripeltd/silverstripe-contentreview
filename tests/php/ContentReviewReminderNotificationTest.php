<?php

namespace SilverStripe\ContentReview\Tests;

use Page;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\CMS\Controllers\CMSPageEditController;
use SilverStripe\ContentReview\Extensions\ContentReviewCMSExtension;
use SilverStripe\ContentReview\Extensions\ContentReviewDefaultSettings;
use SilverStripe\ContentReview\Extensions\ContentReviewOwner;
use SilverStripe\ContentReview\Extensions\SiteTreeContentReview;
use SilverStripe\ContentReview\Tasks\ContentReviewReminderEmails;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\SiteConfig\SiteConfig;

class ContentReviewReminderNotificationTest extends SapphireTest
{
    /**
     * @var string
     */
    protected static $fixture_file = 'ContentReviewTest.yml';

    protected function setUp(): void
    {
        parent::setUp();

        // Hack to ensure only desired siteconfig is scaffolded
        $desiredID = $this->idFromFixture(SiteConfig::class, 'mysiteconfig');
        foreach (SiteConfig::get()->exclude('ID', $desiredID) as $config) {
            $config->delete();
        }
    }

    /**
     * @var array
     */
    protected static $required_extensions = [
        SiteTree::class => [SiteTreeContentReview::class],
        Group::class => [ContentReviewOwner::class],
        Member::class => [ContentReviewOwner::class],
        CMSPageEditController::class => [ContentReviewCMSExtension::class],
        SiteConfig::class => [ContentReviewDefaultSettings::class],
    ];

    /**
     * @dataProvider ContentReviewReminderNotificationDataProvider
     */
    public function testContentReviewReminderEmailsByIntervals(string $date, string $interval)
    {
        DBDatetime::set_mock_now('2010-02-24 12:00:00');

        // Set template variables (as per variable case)
        $ToEmail = 'author@example.com';
        $Subject = 'You have upcoming reviews!';

        /** @var Page|SiteTreeContentReview $childParentPage */
        $childParentPage = $this->objFromFixture(Page::class, 'contact');
        $childParentPage->NextReviewDate = $date;
        $childParentPage->write();

        $task = new ContentReviewReminderEmails();
        $task->run(new HTTPRequest('GET', '/dev/tasks/ContentReviewReminderEmails'));

        $email = $this->findEmail($ToEmail, null, $Subject);
        $this->assertNotNull($email, "Email hasn't been sent.");
        $this->assertRegExp($interval, $email['HtmlContent'], 'Email did not contain correct interval.');

        DBDatetime::clear_mock_now();
    }

    public function testContentReviewReminderEmailsNoReminderSent()
    {
        DBDatetime::set_mock_now('2010-02-24 12:00:00');

        /** @var Page|SiteTreeContentReview $childParentPage */
        $childParentPage = $this->objFromFixture(Page::class, 'contact');
        $childParentPage->NextReviewDate = '2010-03-24' ;
        $childParentPage->write();

        $task = new ContentReviewReminderEmails();
        $task->run(new HTTPRequest('GET', '/dev/tasks/ContentReviewReminderEmails'));

        // Set template variables (as per variable case)
        $ToEmail = 'author@example.com';
        $Subject = 'You have upcoming reviews!';

        $email = $this->findEmail($ToEmail, null, $Subject);
        $this->assertNull($email, "No reminder since review date is overdue");

        DBDatetime::clear_mock_now();
    }

    /**
     * @return string[][]
     */
    public function ContentReviewReminderNotificationDataProvider(): array
    {
        return [
            '7 days interval' => ['2010-03-03', '/7 days/'],
            '30 days interval' => ['2010-03-26', '/30 days/'],
            '60 days interval' => ['2010-04-25', '/60 days/'],
        ];
    }

    /**
     * Deletes all pages except those passes in to the $ids parameter
     *
     * @param  array  $ids Page IDs which will NOT be deleted
     */
    private function deleteAllPagesExcept(array $ids)
    {
        $pages = SiteTree::get()->exclude('ID', $ids);

        foreach ($pages as $page) {
            $page->delete();
        }
    }
}
