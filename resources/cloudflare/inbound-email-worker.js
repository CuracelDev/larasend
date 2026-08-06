/**
 * Larasend inbound email worker.
 *
 * Cloudflare Email Routing invokes this handler for messages matching the
 * routing rule and it forwards the raw MIME to Larasend for parsing and
 * storage. Deployed automatically by Larasend; LARASEND_INBOUND_URL is set
 * as a plain-text binding at upload time.
 *
 * Throwing on failure defers the message so the sending server retries —
 * emails are never silently dropped when Larasend is unreachable.
 */
export default {
    async email(message, env) {
        const response = await fetch(env.LARASEND_INBOUND_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'message/rfc822',
                'Larasend-Envelope-From': message.from,
                'Larasend-Envelope-To': message.to,
            },
            body: message.raw,
        });

        if (!response.ok) {
            throw new Error(
                `Larasend inbound endpoint responded ${response.status}`,
            );
        }
    },
};
