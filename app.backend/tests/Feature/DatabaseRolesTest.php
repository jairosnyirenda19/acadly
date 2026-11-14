<?php

declare(strict_types=1);

use Acadly\Kernel\Database\AppendOnly;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| The application runs as a role that cannot change the schema and cannot
| alter history. These tests prove it against the real database.
*/

beforeEach(function (): void {
    $owner = Schema::connection('pgsql_owner');
    $owner->dropIfExists('probe_entries');
    $owner->create('probe_entries', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('note');
    });

    DB::connection('pgsql_owner')->transaction(function (): void {
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection('pgsql_owner');

        try {
            AppendOnly::protect('probe_entries');
        } finally {
            DB::setDefaultConnection($previous);
        }
    });
});

afterEach(function (): void {
    Schema::connection('pgsql_owner')->dropIfExists('probe_entries');
});

it('runs as a role that is not a superuser and cannot bypass row security', function (): void {
    $role = DB::selectOne('select rolsuper, rolbypassrls, rolcreatedb, rolcreaterole from pg_roles where rolname = current_user');

    expect(DB::selectOne('select current_user as name')->name)->toBe('acadly_app')
        ->and($role->rolsuper)->toBeFalse()
        ->and($role->rolbypassrls)->toBeFalse()
        ->and($role->rolcreatedb)->toBeFalse()
        ->and($role->rolcreaterole)->toBeFalse();
});

it('cannot change the schema as the application role', function (): void {
    Schema::create('probe_forbidden', function (Blueprint $table): void {
        $table->uuid('id')->primary();
    });
})->throws(QueryException::class);

it('can add and read rows of an append-only table', function (): void {
    DB::table('probe_entries')->insert(['id' => (string) str()->uuid7(), 'note' => 'first']);

    expect(DB::table('probe_entries')->count())->toBe(1);
});

it('cannot update a row of an append-only table', function (): void {
    DB::table('probe_entries')->insert(['id' => (string) str()->uuid7(), 'note' => 'first']);

    DB::table('probe_entries')->update(['note' => 'changed']);
})->throws(QueryException::class);

it('cannot delete a row of an append-only table', function (): void {
    DB::table('probe_entries')->insert(['id' => (string) str()->uuid7(), 'note' => 'first']);

    DB::table('probe_entries')->delete();
})->throws(QueryException::class);

it('refuses a change to an append-only table from the owner role too', function (): void {
    DB::table('probe_entries')->insert(['id' => (string) str()->uuid7(), 'note' => 'first']);

    DB::connection('pgsql_owner')->table('probe_entries')->update(['note' => 'changed']);
})->throws(QueryException::class, 'append-only');
