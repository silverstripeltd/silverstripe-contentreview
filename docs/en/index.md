# Content Review module developer documentation

## Configuration

### Global settings

The module is set up in the `Settings` section of the CMS, see the [User guide](userguide/index.md).

### Email notifications

In order for the contentreview module to send overdue review and reminder email notifications, you need to *either*:

 * Setup the `ContentReviewEmails` script to run daily via a system cron job.
 * Setup the `ContentReviewReminderEmails` script to run daily via a system cron job.
 * Install the [queuedjobs](https://github.com/symbiote/silverstripe-queuedjobs) module and follow the configuration steps to create a cron job for that module. Once installed, you can just run `dev/build` to have a job created, which will run at 9am every day by default.

## Reminders
Content Review module has two notification workflows.
This allows for authors to be reminded of upcoming reviews and then reminded of overdue review(s).

1. A review date is assigned to a page that will send notifications to the author on the review date at a frequency specified by site admins.
2. Concurrent to the review date an interval configuration of `7`, `30` and `60` days will check if a piece of content is x days away from review and send a reminder that day to the author.

## Using

See the [user guide](userguide/index.md).

## Testing

cd to the site root, and run:

```sh
$ php vendor/bin/behat @contentreview
```

or to run the unit test suite:

```sh
$ php vendor/bin/phpunit contentreview/tests
```

## Migration

If you are upgrading from an older version, you may need to run the `ContentReviewOwnerMigrationTask`
