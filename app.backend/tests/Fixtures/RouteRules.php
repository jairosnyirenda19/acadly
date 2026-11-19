<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/**
 * How the walking tests treat each route of the API: the cross-scope test
 * (03 §3) and the boundary test (D-099). A new route is covered the moment
 * it is added; one that may answer must be listed here with the reason.
 */
final class RouteRules
{
    /** Routes open to anyone: they reveal nothing about a place. */
    public const array OPEN_ROUTES = [
        'sanctum.csrf-cookie', 'auth.sign-in', 'auth.two-step', 'academics.calendar.feed', 'auth.forgot-password', 'auth.reset-password', 'auth.phone.code', 'auth.phone.sign-in',
        'kernel.health', 'kernel.ready', 'kernel.school', 'institution.logo.public', 'notify.reports', 'kernel.check',
    ];

    /** Routes about the account itself, not about a place. */
    public const array OWN_ROUTES = ['auth.sign-out', 'me', 'me.context', 'me.account', 'me.account.notices', 'me.account.password', 'me.account.two-step', 'me.account.two-step.confirm', 'me.account.two-step.off', 'me.calendar', 'me.calendar.on', 'me.calendar.off', 'me.teaching', 'search', 'search.places', 'me.phone.code', 'me.phone.confirm', 'me.phone.remove', 'me.notifications', 'me.notifications.read', 'view-as.leave', 'approvals.waiting', 'portal.family', 'portal.confirm'];

    /** Routes decided per record by the owning domain, and proved in its own tests. */
    public const array RECORD_ROUTES = [
        'documents.store' => 'DocumentsTest: refuses to store for a person without the kind permission',
        'documents.show' => 'DocumentsTest: answers not found to a person who may not open the document',
        'documents.content' => 'DocumentsTest: answers not found to a person who may not open the document',
        'documents.versions.store' => 'DocumentsTest: answers not found to a person who may not open the document',
        'documents.trash' => 'DocumentsTest: answers not found to a person who may not open the document',
        'documents.restore' => 'DocumentsTest: trashes with a reason, restores a batch together',
        'assessment.publishing.publish' => 'AssessmentTest: publishes a ready class; a class outside the reach of the viewer is not found',
        'people.import.template' => 'PeopleImportTest: the template lists only the roles and places the viewer can give',
        'people.import.check' => 'PeopleImportTest: a row for a place outside the viewer\'s reach is not imported',
        'people.import' => 'PeopleImportTest: a row for a place outside the viewer\'s reach is not imported',
        'learners.import.template' => 'LearnerImportTest: the template lists only the classes the viewer may admit into',
        'learners.import.check' => 'LearnerImportTest: a row for a class outside the viewer\'s reach is not imported',
        'learners.import' => 'LearnerImportTest: a row for a class outside the viewer\'s reach is not imported',
        'grids.views.store' => 'GridTest: keeps a person\'s columns and named views (a person\'s own, over rows within reach)',
        'grids.views.update' => 'GridTest: keeps a person\'s columns and named views',
        'grids.views.delete' => 'GridTest: keeps a person\'s columns and named views',
        'grids.columns' => 'GridTest: keeps a person\'s columns and named views',
        'grids.exports.store' => 'GridTest: builds an export in the background (only rows within reach)',
        'grids.exports.show' => 'GridTest: builds an export in the background (another person: not found)',
        'grids.exports.file' => 'GridTest: builds an export in the background (another person: not found)',
        'grids.bulk' => 'GridTest: changes the class of every learner that matches (rows outside reach are dropped)',
        'fees.payments.store' => 'FeesTest: takes payments only where they may receive them (a child outside reach is not found)',
    ];

    /**
     * School-wide settings (D-099): they name no record of any division, and a
     * person with a role for the whole school may change them from any place.
     * The console offers them in the school view. The boundary test checks only
     * that their answers name nothing of another division.
     */
    public const array SCHOOL_WIDE = [
        'institution.update', 'institution.look', 'institution.logo', 'institution.levels', 'institution.offerings', 'institution.campuses.store', 'institution.campuses.update',
        'academics.year', 'academics.terms', 'academics.next-year.save', 'academics.classes',
        'messages.channels.sms', 'messages.channels.email', 'messages.limits', 'messages.test', 'messages.notice.save', 'messages.notice.reset',
        'modules.update', 'roles.store', 'roles.update', 'roles.destroy', 'approvals.topic.save',
        'assessment.publishing.settings', 'assessment.scales.store', 'assessment.scales.update', 'conduct.kinds.save', 'conduct.watch.save', 'support.rules.save',
        'fees.items.save', 'fees.shop.save', 'learner-document-types.store', 'learner-document-types.update', 'learner-document-types.retire',
    ];

    /**
     * The whole-school overview's read-only lists (D-100): in the school view
     * only, for those who may see the overview, each row marked by its division.
     * The owner chose them; DashboardTest proves who may open them and that
     * inside a division they are not found.
     */
    public const array OVERVIEW_LISTS = ['learners.school', 'fees.ledger'];

    /** Routes that name only the place a record is in, to someone who may work there, so the console can offer to switch (D-099). */
    public const array PLACE_OF = ['learners.place'];

    /**
     * Lists that answer every viewer with only what is inside the viewer's
     * reach (and place). The tests check that nothing outside is in the answer.
     */
    public const array LIST_ROUTES = ['people.index', 'academics.teaching.index', 'academics.timetable.index', 'learners.index', 'learners.grid', 'people.grid', 'registers.index',
        'registers.lessons', 'registers.missed', 'registers.levels', 'assessment.mine', 'assessment.options', 'assessment.grid', 'assessment.publishing', 'fees.shop.index', 'conduct.kinds.index', 'support.watch',
        'assessment.rooms', 'assessment.reviews', 'assessment.reviews.share', 'pickup.desk', 'divisions.overview', 'institution.dashboard', 'institution.today'];
}
