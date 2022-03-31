<?php

namespace SilverStripe\ContentReview\Extensions;

use SilverStripe\Core\Config\Config;
use SilverStripe\Control\Email\Email;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HTMLEditor\HTMLEditorField;
use SilverStripe\Forms\HTMLEditor\TinyMCEConfig;
use SilverStripe\Forms\ListboxField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\TextAreaField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataExtension;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;

/**
 * This extensions add a default schema for new pages and pages without a content
 * review setting.
 *
 * @property int $ReviewPeriodDays
 */
class ContentReviewDefaultSettings extends DataExtension
{
    /**
     * @config
     *
     * @var array
     */
    private static $db = array(
        'ReviewPeriodDays' => 'Int',
        'ReviewFrom' => 'Varchar(255)',
        'ReviewSubject' => 'Varchar(255)',
        'ReviewBody' => 'HTMLText',
        'ReminderSubject' => 'Varchar(255)',
        'ReminderBody' => 'HTMLText',
    );

    /**
     * @config
     *
     * @var array
     */
    private static $defaults = array(
        'ReviewSubject' => 'Page(s) are due for content review',
        'ReviewBody' => '<h2>Page(s) due for review</h2>'
            . '<p>There are $PagesCount pages that are due for review today by you.</p>',
        'ReminderSubject' => 'Reminder: Page(s) are upcoming for content review',
        'ReminderBody' => '<h2>Reminder: Your Page(s) are approaching overdue for review</h2>'
            . '<p>There are $PagesCount pages that have reviews upcoming for you.</p>',
    );

    /**
     * @config
     *
     * @var array
     */
    private static $many_many = [
        'ContentReviewGroups' => Group::class,
        'ContentReviewUsers' => Member::class,
    ];

    /**
     * Template to use for content review emails.
     *
     * This should contain an $EmailBody variable as a placeholder for the user-defined copy
     *
     * @config
     *
     * @var string
     */
    private static $content_review_template = 'SilverStripe\\ContentReview\\ContentReviewEmail';

    /**
     * Template to use for Reminder content review emails.
     *
     * This should contain an $EmailBody variable as a placeholder for the user-defined copy
     *
     * @config
     *
     * @var string
     */
    private static $content_review_reminder_template = 'SilverStripe\\ContentReview\\ContentReviewReminderEmail';

    /**
     * @return string
     */
    public function getOwnerNames()
    {
        $names = [];

        foreach ($this->OwnerGroups() as $group) {
            $names[] = $group->getBreadcrumbs(' > ');
        }

        foreach ($this->OwnerUsers() as $group) {
            $names[] = $group->getName();
        }

        return implode(', ', $names);
    }

    /**
     * @return ManyManyList
     */
    public function OwnerGroups()
    {
        return $this->owner->getManyManyComponents('ContentReviewGroups');
    }

    /**
     * @return ManyManyList
     */
    public function OwnerUsers()
    {
        return $this->owner->getManyManyComponents('ContentReviewUsers');
    }

    /**
     * @param FieldList $fields
     */
    public function updateCMSFields(FieldList $fields)
    {
        $helpText = LiteralField::create(
            'ContentReviewHelp',
            _t(
                __CLASS__ . '.DEFAULTSETTINGSHELP',
                'These settings will apply to all pages that do not have a specific Content Review schedule.'
            )
        );

        $fields->addFieldToTab('Root.ContentReview', $helpText);

        $reviewFrequency = DropdownField::create(
            'ReviewPeriodDays',
            _t(__CLASS__ . '.REVIEWFREQUENCY', 'Review frequency'),
            SiteTreeContentReview::get_schedule()
        )
            ->setDescription(_t(
                __CLASS__ . '.REVIEWFREQUENCYDESCRIPTION',
                'The review date will be set to this far in the future, whenever the page is published.'
            ));

        $fields->addFieldToTab('Root.ContentReview', $reviewFrequency);

        $users = Permission::get_members_by_permission([
            'CMS_ACCESS_CMSMain',
            'ADMIN',
        ]);

        $usersMap = $users->map('ID', 'Title')->toArray();
        asort($usersMap);

        $userField = ListboxField::create('OwnerUsers', _t(__CLASS__ . '.PAGEOWNERUSERS', 'Users'), $usersMap)
            ->setAttribute('data-placeholder', _t(__CLASS__ . '.ADDUSERS', 'Add users'))
            ->setDescription(_t(__CLASS__ . '.OWNERUSERSDESCRIPTION', 'Page owners that are responsible for reviews'));

        $fields->addFieldToTab('Root.ContentReview', $userField);

        $groupsMap = [];

        foreach (Group::get() as $group) {
            // Listboxfield values are escaped, use ASCII char instead of &raquo;
            $groupsMap[$group->ID] = $group->getBreadcrumbs(' > ');
        }

        asort($groupsMap);

        $groupField = ListboxField::create('OwnerGroups', _t(__CLASS__ . '.PAGEOWNERGROUPS', 'Groups'), $groupsMap)
            ->setAttribute('data-placeholder', _t(__CLASS__ . '.ADDGROUP', 'Add groups'))
            ->setDescription(_t(__CLASS__ . '.OWNERGROUPSDESCRIPTION', 'Page owners that are responsible for reviews'));

        $fields->addFieldToTab('Root.ContentReview', $groupField);

        // Email content
        $fields->addFieldsToTab(
            'Root.ContentReview',
            [
                TextField::create('ReviewFrom', _t(__CLASS__ . '.EMAILFROM', 'From email address'))
                    ->setDescription(_t(__CLASS__ . '.EMAILFROM_RIGHTTITLE', 'e.g: do-not-reply@site.com')),
                TextField::create(
                    'ReviewSubject',
                    _t(__CLASS__ . '.OVERDUEEMAILSUBJECT', 'Overdue review subject line')
                ),
                $overdueReviewBody = HTMLEditorField::create(
                    'ReviewBody',
                    _t(__CLASS__ . '.OVERDUEEMAILTEMPLATE', 'Overdue email template')
                ),
                TextField::create(
                    'ReminderSubject',
                    _t(__CLASS__ . '.REMINDEREMAILSUBJECT', 'Reminder review subject line')
                ),
                $reminderReviewBody = HTMLEditorField::create(
                    'ReminderBody',
                    _t(__CLASS__ . '.REMINDEREMAILTEMPLATE', 'Reminder Email template')
                ),
                LiteralField::create(
                    'TemplateHelp',
                    $this->owner->renderWith('SilverStripe\\ContentReview\\ContentReviewAdminHelp')
                ),
            ]
        );

        // set up tinymce config for our body fields
        $overdueReviewBody->setEditorConfig($this->getTinyMCEConfig($overdueReviewBody->getEditorConfig()));
        $reminderReviewBody->setEditorConfig($this->getTinyMCEConfig($reminderReviewBody->getEditorConfig()));
    }

    /**
     * Get the TinyMCEConfig that should be used for the email template preview
     *
     * @return TinyMCEConfig
     */
    private function getTinyMCEConfig(
        TinyMCEConfig $config
    ): TinyMCEConfig {
        $editorButtonsGroupSeparator = '|';
        $allowedEditorButtons = [
            'undo',
            'redo',
            $editorButtonsGroupSeparator,
            'bold',
            'italic',
            'underline',
            $editorButtonsGroupSeparator,
            'bullist',
            'numlist',
            $editorButtonsGroupSeparator,
            'sslink',
            $editorButtonsGroupSeparator,
            'formatselect',
            $editorButtonsGroupSeparator,
            'code',
        ];

        $config->setButtonsForLine(1, $allowedEditorButtons);
        $config->setButtonsForLine(2, []);
        $config->setButtonsForLine(3, []);

        return $config;
    }

    /**
     * Get all Members that are default Content Owners. This includes checking group hierarchy
     * and adding any direct users.
     *
     * @return ArrayList
     */
    public function ContentReviewOwners()
    {
        return SiteTreeContentReview::merge_owners($this->OwnerGroups(), $this->OwnerUsers());
    }

    /**
     * Get the review body, falling back to the default if left blank.
     *
     * @return string HTML text
     */
    public function getReviewBody()
    {
        return $this->getWithDefault('ReviewBody');
    }

    /**
     * Get the review subject line, falling back to the default if left blank.
     *
     * @return string plain text value
     */
    public function getReviewSubject()
    {
        return $this->getWithDefault('ReviewSubject');
    }

    /**
     * Get the "from" field for review emails.
     *
     * @return string
     */
    public function getReviewFrom()
    {
        $from = $this->owner->getField('ReviewFrom');
        if ($from) {
            return $from;
        }

        // Fall back to admin email
        return Config::inst()->get(Email::class, 'admin_email');
    }

    /**
     * Get the value of a user-configured field, falling back to the default if left blank.
     *
     * @param string $field
     *
     * @return string
     */
    protected function getWithDefault($field)
    {
        $value = $this->owner->getField($field);
        if ($value) {
            return $value;
        }
        // fallback to default value
        $defaults = $this->owner->config()->get('defaults');
        if (isset($defaults[$field])) {
            return $defaults[$field];
        }
    }
}
