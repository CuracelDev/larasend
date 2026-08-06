<?php

function nullableDeduplicationMigrationFile(): string
{
    return database_path('migrations/2026_08_05_152059_add_deduplication_keys_to_email_events_and_inbound_emails.php');
}

function nullableIdempotencyMigrationFile(): string
{
    return database_path('migrations/2026_08_05_152739_add_idempotency_columns_to_emails_table.php');
}

it('uses SQL Server filtered unique indexes for nullable deduplication keys', function () {
    $migrationSource = file_get_contents(nullableDeduplicationMigrationFile());

    expect($migrationSource)
        ->toContain("getDriverName() === 'sqlsrv'")
        ->toContain("EMAIL_EVENTS_INDEX = 'email_events_provider_event_id_unique'")
        ->toContain('ON [email_events] ([provider_event_id]) WHERE [provider_event_id] IS NOT NULL')
        ->toContain("INBOUND_EMAILS_INDEX = 'inbound_emails_deduplication_key_unique'")
        ->toContain('ON [inbound_emails] ([deduplication_key]) WHERE [deduplication_key] IS NOT NULL');
});

it('uses a SQL Server filtered unique index for nullable idempotency keys', function () {
    $migrationSource = file_get_contents(nullableIdempotencyMigrationFile());

    expect($migrationSource)
        ->toContain("getDriverName() === 'sqlsrv'")
        ->toContain("IDEMPOTENCY_INDEX = 'emails_project_id_idempotency_key_unique'")
        ->toContain('ON [emails] ([project_id], [idempotency_key]) WHERE [idempotency_key] IS NOT NULL');
});
