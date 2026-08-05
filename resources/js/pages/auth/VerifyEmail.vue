<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

defineOptions({
    layout: {
        title: 'Verify email',
        description: 'Verify your email address before continuing to Larasend.',
    },
});

defineProps<{
    status?: string;
    controlMailConfigured: boolean;
}>();
</script>

<template>
    <Head title="Email verification" />

    <div
        v-if="status === 'verification-link-sent'"
        class="mb-4 text-center text-sm font-medium text-green-600"
    >
        A new verification link has been sent to the email address you provided
        during registration.
    </div>

    <div
        v-if="!controlMailConfigured"
        class="mb-4 rounded-md border border-amber-500/30 bg-amber-500/10 p-3 text-center text-sm text-amber-700 dark:text-amber-300"
    >
        Control email is unavailable. Ask the server administrator to run
        <code>php artisan larasend:verify-user your@email.com --force</code>.
    </div>

    <Form
        v-if="controlMailConfigured"
        v-bind="send.form()"
        class="space-y-6 text-center"
        v-slot="{ processing }"
    >
        <Button :disabled="processing" variant="secondary">
            <Spinner v-if="processing" />
            Resend verification email
        </Button>

        <TextLink :href="logout()" as="button" class="mx-auto block text-sm">
            Log out
        </TextLink>
    </Form>
</template>
