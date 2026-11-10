/** The text when a read-only adapter comes without the server's message. */
export const READ_ONLY_FALLBACK = 'Retry and forget are not available for this failed-job driver: its failures are shown read-only.';

/**
 * The read-only failed-record driver from `GET /api/health`
 * (`failed_records`): `{reason, message}` when the
 * adapter is `read_only` (for example the file or DynamoDB driver, or a
 * failer that the application bound itself), else null. The screens then
 * show no Retry or Forget action for failures and show the message once.
 *
 * Null also while the health is not loaded, unreadable (`failed_records`
 * null) or from an older server: the actions stay, because the server
 * still refuses each one with `provider_read_only`.
 */
export function failedRecordsReadOnly(health) {
    const records = health && typeof health === 'object' ? health.failed_records : null;

    if (!records || typeof records !== 'object' || records.adapter !== 'read_only') return null;

    return {
        reason: typeof records.reason === 'string' && records.reason !== '' ? records.reason : null,
        message: typeof records.message === 'string' && records.message !== '' ? records.message : READ_ONLY_FALLBACK,
    };
}
