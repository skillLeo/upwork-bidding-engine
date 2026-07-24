<script setup>
import { computed, onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import { apiClient, apiErrorMessage } from "@/lib/api-client";
import { useAuthStore } from "@/stores/auth";
import { useBrandingStore } from "@/stores/branding";
import AuthLayout from "@/components/auth/AuthLayout.vue";
import AuthField from "@/components/auth/AuthField.vue";
import AuthButton from "@/components/auth/AuthButton.vue";
import GoogleButton from "@/components/auth/GoogleButton.vue";

const router = useRouter();
const auth = useAuthStore();
const branding = useBrandingStore();

// open → the full self-serve form; invite_code → a code field that hands off
// to the existing invite-accept flow; closed → a short state, no form. Server
// re-checks the mode on submit, so the client copy is only ever a hint.
const mode = computed(() => branding.signupMode);

const name = ref("");
const email = ref("");
const password = ref("");
const workspace = ref("");
const errors = ref({});
const formError = ref("");
const submitting = ref(false);

const inviteCode = ref("");
const inviteError = ref("");

const apiBase = (import.meta.env.VITE_API_URL ?? "/api").replace(/\/$/, "");
function continueWithGoogle() {
  window.location.href = `${apiBase}/auth/google/redirect`;
}

onMounted(() => {
  // Make sure we have the real mode before painting a form (the store may
  // still hold its default from a cold boot).
  branding.fetch();
});

async function onSubmit() {
  errors.value = {};
  formError.value = "";

  // Catch the obvious gaps before a round-trip; the server still re-validates.
  const next = {};
  if (!name.value) next.name = "Enter your name.";
  if (!/^\S+@\S+\.\S+$/.test(email.value)) next.email = "Enter a valid email address.";
  if (password.value.length < 8) next.password = "Use at least 8 characters.";
  if (!workspace.value) next.workspace_name = "Name your workspace.";
  if (Object.keys(next).length) {
    errors.value = next;
    return;
  }

  submitting.value = true;
  try {
    const res = await apiClient.post("/auth/register", {
      name: name.value,
      email: email.value,
      password: password.value,
      password_confirmation: password.value,
      workspace_name: workspace.value,
    });
    auth.setAuth(res.data.data.token, res.data.data.user, true);
    router.replace("/leads");
  } catch (error) {
    const fieldErrors = error.response?.data?.errors;
    if (fieldErrors) {
      errors.value = Object.fromEntries(
        Object.entries(fieldErrors).map(([k, v]) => [k, v[0]]),
      );
    } else {
      formError.value = apiErrorMessage(error, "Could not create your workspace.");
    }
  } finally {
    submitting.value = false;
  }
}

function useInviteCode() {
  inviteError.value = "";
  const code = inviteCode.value.trim();
  if (!code) {
    inviteError.value = "Enter the invite code from your email.";
    return;
  }
  router.push({ name: "accept-invite", query: { token: code } });
}
</script>

<template>
  <AuthLayout>
    <!-- closed: no form, one action. -->
    <template v-if="mode === 'closed'">
      <h1 class="auth-h1">
        Sign-up is closed
      </h1>
      <p class="auth-sub">
        New workspaces are invite-only right now. If someone has invited you, use the link in
        your email to join.
      </p>
      <router-link to="/login" class="auth-link mt-8 inline-block">Back to sign in</router-link>
    </template>

    <!-- invite_code: a code field that continues to the accept-invite flow. -->
    <template v-else-if="mode === 'invite_code'">
      <h1 class="auth-h1">
        Join a workspace
      </h1>
      <p class="auth-sub">
        {{ branding.name }} is invite-only. Enter the code from your invitation to continue.
      </p>

      <form class="mt-8 flex flex-col gap-4" novalidate @submit.prevent="useInviteCode">
        <AuthField
          id="invite-code"
          v-model="inviteCode"
          label="Invite code"
          autocomplete="off"
          spellcheck="false"
          :error="inviteError"
        />
        <AuthButton type="submit">Continue</AuthButton>
      </form>

      <p class="mt-8" style="font-size: 13px; color: var(--paper-ink-2)">
        Already a member?
        <router-link to="/login" class="auth-link">Sign in</router-link>
      </p>
    </template>

    <!-- open: full self-serve sign-up. -->
    <template v-else>
      <h1 class="auth-h1">
        Get started
      </h1>
      <p class="auth-sub">
        Create your {{ branding.name }} workspace.
      </p>

      <form class="mt-8 flex flex-col gap-4" novalidate @submit.prevent="onSubmit">
        <AuthField
          id="name"
          v-model="name"
          label="Your name"
          autocomplete="name"
          :error="errors.name"
        />
        <AuthField
          id="email"
          v-model="email"
          label="Email"
          type="email"
          inputmode="email"
          autocomplete="email"
          autocapitalize="none"
          autocorrect="off"
          spellcheck="false"
          :error="errors.email"
        />
        <AuthField
          id="password"
          v-model="password"
          label="Password"
          type="password"
          autocomplete="new-password"
          :error="errors.password"
        />
        <AuthField
          id="workspace"
          v-model="workspace"
          label="Workspace name"
          autocomplete="organization"
          :error="errors.workspace_name"
        />

        <div class="auth-error" :class="{ open: !!formError }" aria-live="polite">
          <span>{{ formError }}</span>
        </div>

        <AuthButton type="submit" :loading="submitting">Create workspace</AuthButton>
      </form>

      <div class="auth-divider my-6"><span>or</span></div>

      <GoogleButton @click="continueWithGoogle">Continue with Google</GoogleButton>

      <p class="mt-8" style="font-size: 13px; color: var(--paper-ink-2)">
        Already have an account?
        <router-link to="/login" class="auth-link">Sign in</router-link>
      </p>
    </template>
  </AuthLayout>
</template>
