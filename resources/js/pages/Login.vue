<script setup>
import { ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { apiClient, apiErrorMessage } from "@/lib/api-client";
import { useAuthStore } from "@/stores/auth";
import { useBrandingStore } from "@/stores/branding";
import AuthLayout from "@/components/auth/AuthLayout.vue";
import AuthField from "@/components/auth/AuthField.vue";
import AuthButton from "@/components/auth/AuthButton.vue";
import OtpInput from "@/components/auth/OtpInput.vue";
import GoogleButton from "@/components/auth/GoogleButton.vue";

const router = useRouter();
const route = useRoute();
const auth = useAuthStore();
const branding = useBrandingStore();

const email = ref("");
const password = ref("");
const remember = ref(true);
const formError = ref("");
const submitting = ref(false);

// credentials → the second factor, if the account has one. TOTP is checked
// first server-side, so a user with both is only ever asked for the one.
const view = ref("credentials"); // credentials | totp | otp

const challenge = ref("");
const code = ref("");
const codeError = ref("");
const useRecovery = ref(false);
const recovery = ref("");

function landing() {
  const target = route.query.redirect || auth.takeLastRoute();
  return typeof target === "string" && target.startsWith("/") ? target : "/leads";
}

const apiBase = (import.meta.env.VITE_API_URL ?? "/api").replace(/\/$/, "");
function continueWithGoogle() {
  window.location.href = `${apiBase}/auth/google/redirect`;
}

// The server decides whether dev quick login exists (config/skillleo.php); it
// is off in production, so this block never renders there.
const devQuickLogin =
  document.querySelector('meta[name="dev-quick-login"]')?.content === "1";

async function quickLogin(role) {
  submitting.value = true;
  try {
    const res = await apiClient.post("/auth/dev-login", { role });
    auth.setAuth(res.data.data.token, res.data.data.user, remember.value);
    router.replace(landing());
  } catch (error) {
    formError.value = apiErrorMessage(error, `Could not sign in as ${role}.`);
  } finally {
    submitting.value = false;
  }
}

async function onSubmit() {
  formError.value = "";
  if (!email.value || !password.value) {
    formError.value = "Enter your email and password.";
    return;
  }
  submitting.value = true;
  try {
    const res = await apiClient.post("/auth/login", {
      email: email.value,
      password: password.value,
    });
    const data = res.data.data;

    if (data.requires_totp) {
      challenge.value = data.challenge;
      view.value = "totp";
      return;
    }
    if (data.requires_otp) {
      challenge.value = data.challenge;
      view.value = "otp";
      return;
    }
    auth.setAuth(data.token, data.user, remember.value);
    router.replace(landing());
  } catch (error) {
    formError.value = apiErrorMessage(error, "That email or password is incorrect.");
  } finally {
    submitting.value = false;
  }
}

async function verify() {
  codeError.value = "";
  const value = useRecovery.value ? recovery.value : code.value;
  if (!value) {
    codeError.value = useRecovery.value ? "Enter a recovery code." : "Enter the 6-digit code.";
    return;
  }
  submitting.value = true;
  try {
    const endpoint = view.value === "totp" ? "/auth/verify-totp" : "/auth/verify-otp";
    const res = await apiClient.post(endpoint, { challenge: challenge.value, code: value });
    auth.setAuth(res.data.data.token, res.data.data.user, remember.value);
    router.replace(landing());
  } catch (error) {
    codeError.value = apiErrorMessage(error, "That code is incorrect or has expired.");
  } finally {
    submitting.value = false;
  }
}

function backToCredentials() {
  view.value = "credentials";
  challenge.value = "";
  code.value = "";
  recovery.value = "";
  codeError.value = "";
  useRecovery.value = false;
}
</script>

<template>
  <AuthLayout>
    <!-- Second factor: six per-digit boxes (or a recovery code). Same shell,
         same tokens — screen 5 of the system. -->
    <template v-if="view === 'totp' || view === 'otp'">
      <h1 class="auth-h1">
        Two-step verification
      </h1>
      <p class="auth-sub">
        {{
          view === "otp"
            ? "Enter the 6-digit code we emailed you."
            : useRecovery
              ? "Enter one of your recovery codes."
              : "Enter the 6-digit code from your authenticator app."
        }}
      </p>

      <form class="mt-8" novalidate @submit.prevent="verify">
        <div v-if="useRecovery">
          <label for="recovery" class="auth-label">Recovery code</label>
          <input
            id="recovery"
            v-model="recovery"
            class="auth-input"
            style="margin-top: 6px"
            :class="{ 'is-invalid': !!codeError }"
            autocomplete="one-time-code"
            inputmode="text"
            placeholder="xxxx-xxxx"
          />
        </div>
        <OtpInput v-else v-model="code" :invalid="!!codeError" :disabled="submitting" />

        <div class="auth-error" :class="{ open: !!codeError }" aria-live="polite">
          <span>{{ codeError }}</span>
        </div>

        <AuthButton type="submit" class="mt-6" :loading="submitting">Verify</AuthButton>
      </form>

      <button
        type="button"
        class="auth-link mt-6 block cursor-pointer border-0 bg-transparent p-0 text-left"
        @click="(useRecovery = !useRecovery), (codeError = '')"
      >
        {{ useRecovery ? "Use your authenticator app" : "Use a recovery code instead" }}
      </button>
      <button
        type="button"
        class="auth-link mt-3 block cursor-pointer border-0 bg-transparent p-0 text-left"
        @click="backToCredentials"
      >
        Back to sign in
      </button>
    </template>

    <!-- Sign in -->
    <template v-else>
      <h1 class="auth-h1">
        Sign in
      </h1>
      <p class="auth-sub">
        Continue to your {{ branding.name }} workspace.
      </p>

      <form class="mt-8 flex flex-col gap-4" novalidate @submit.prevent="onSubmit">
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
          @input="formError = ''"
        />

        <!-- Relative wrapper: "Forgot?" is visually on the label row but comes
             after the input in source, so keyboard order is email → password
             → Forgot rather than interrupting the two fields. -->
        <div class="relative">
          <label for="password" class="auth-label">Password</label>
          <input
            id="password"
            v-model="password"
            class="auth-input"
            style="margin-top: 6px"
            type="password"
            autocomplete="current-password"
            @input="formError = ''"
          />
          <router-link
            to="/forgot-password"
            class="auth-link"
            style="position: absolute; top: 0; right: 0"
            >Forgot?</router-link
          >
        </div>

        <div class="auth-error" :class="{ open: !!formError }" aria-live="polite">
          <span>{{ formError }}</span>
        </div>

        <AuthButton type="submit" :loading="submitting">Sign in</AuthButton>
      </form>

      <div class="auth-divider my-6"><span>or</span></div>

      <GoogleButton @click="continueWithGoogle">Continue with Google</GoogleButton>

      <p class="mt-8" style="font-size: 13px; color: var(--paper-ink-2)">
        New here?
        <router-link to="/register" class="auth-link">Get started</router-link>
      </p>

      <!-- Gated by a server flag (config/skillleo.php). Super admin targets
           the platform_owner column directly rather than hoping the oldest
           'admin' happens to be that account. -->
      <div v-if="devQuickLogin" class="mt-6 flex flex-wrap gap-2">
        <AuthButton variant="secondary" :disabled="submitting" @click="quickLogin('platform_owner')">
          Super admin
        </AuthButton>
        <AuthButton variant="secondary" :disabled="submitting" @click="quickLogin('admin')">Admin</AuthButton>
        <AuthButton variant="secondary" :disabled="submitting" @click="quickLogin('bidder')">Bidder</AuthButton>
      </div>
    </template>
  </AuthLayout>
</template>
