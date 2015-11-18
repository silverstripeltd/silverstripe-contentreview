<?php

/**
 * Extend this class when writing unit tests which are compatible with other modules.
 * All compatibility code goes here.
 */
abstract class ContentReviewBaseTest extends FunctionalTest
{
    /**
     * @var bool
     */
    protected $translatableEnabledBefore;

    public function setUp()
    {
        parent::setUp();

        /*
         *  We set the locale for pages explicitly, because if we don't, then we get into a situation
         *  where the page takes on the tester's (your) locale, and any calls to simulate subsequent requests
         *  (e.g. $this->post()) do not seem to get passed the tester's locale, but instead fallback to the default locale.
         *
         *  So we set the pages locale to be the default locale, which will then match any subsequent requests.
         *  
         *  If creating pages in your unit tests (rather than reading from the fixtures file), you must explicitly call
         *  self::compat() on the page, for the same reasons as above.
         */
        if (class_exists("Translatable")) {
            $this->translatableEnabledBefore = SiteTree::has_extension("Translatable");
            SiteTree::remove_extension("Translatable");
        }
    }

    public function tearDown()
    {
        if (class_exists("Translatable")) {
            if ($this->translatableEnabledBefore) {
                SiteTree::add_extension("Translatable");
            }
        }

        parent::tearDown();
    }
}
