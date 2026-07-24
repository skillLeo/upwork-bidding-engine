<script setup>
import { onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { apiClient, apiErrorMessage } from "@/lib/api-client";
import { useAuthStore } from "@/stores/auth";
import AuthLayout from "@/components/auth/AuthLayout.vue";
import AuthDeadEnd from "@/components/auth/AuthDeadEnd.vue";
import AuthButton from "@/components/auth/AuthButton.vue";
import OtpInput from "@/components/auth/OtpInput.vue";

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const view = ref("loading"); // loading | link | totp | otp | error
const errorText = ref("");
const email = ref("");
const linkToken = ref("");
const password = ref("");
const passwordError = ref("");
const code = ref("");
const codeError = ref("");
const challenge = ref("");
const submitting = ref(false);

function landing() {
  return auth.takeLastRoute() || "/leads";
}

async function finishWithToken(token) {
  try {
    const res = await apiClient.get("/me", { headers: { Authorization: `Bearer ${token}` } });
    auth.setAuth(token, res.data.data, true);
    router.replace(landing());
  } catch (err) {
    errorText.value = apiErrorMessage(err, "Could not complete sign-in.");
    view.value = "error";
  }
}

async function submitLink() {
  passwordError.value = "";
  if (!password.value) {
    passwordError.value = "Enter your account password.";
    return;
  }
  submitting.value = true;
  try {
    const res = await apiClient.post("/auth/google/link", {
      link_token: linkToken.value,
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
    await finishWithToken(data.token);
  } catch (err) {
    passwordError.value = apiErrorMessage(err, "That password is incorrect.");
  } finally {
    submitting.value = false;
  }
}

async function submitChallenge() {
  codeError.value = "";
  if (!code.value) {
    codeError.value = "Enter the 6-digit code.";
    return;
  }
  submitting.value = true;
  try {
    const endpoint = view.value === "totp" ? "/auth/verify-totp" : "/auth/verify-otp";
    const res = await apiClient.post(endpoint, { challenge: challenge.value, code: code.value });
    await finishWithToken(res.data.data.token);
  } catch (err) {
    codeError.value = apiErrorMessage(err, "That code is incorrect or has expired.");
  } finally {
    submitting.value = false;
  }
}

// The redirect never carries the token/challenge/email directly — only an
// opaque, single-use handoff code, exchanged here via POST for the payload.
async function exchangeHandoff(handoff) {
  try {
    const res = await apiClient.post("/auth/google/exchange", { handoff });
    const payload = res.data.data;
    if (payload.link_required) {
      linkToken.value = payload.link_token;
      email.value = payload.email;
      view.value = "link";
      return;
    }
    if (payload.requires_totp) {
      challenge.value = payload.challenge;
      view.value = "totp";
      return;
    }
    if (payload.requires_otp) {
      challenge.value = payload.challenge;
      view.value = "otp";
      return;
    }
    if (payload.token) {
      await finishWithToken(payload.token);
      return;
    }
    errorText.value = "Something went wrong finishing Google sign-in.";
    view.value = "error";
  } catch (err) {
    errorText.value = apiErrorMessage(err, "This sign-in link has expired. Try again.");
    view.value = "error";
  }
}

onMounted(() => {
  const q = route.query;
  if (q.error) {
    errorText.value = String(q.error);
    view.value = "error";
    return;
  }
  if (q.handoff) {
    exchangeHandoff(String(q.handoff));
    return;
  }
  errorText.value = "Something went wrong finishing Google sign-in.";
  view.value = "error";
});
</script>

<template>
  <AuthDeadEnd
    v-if="view === 'error'"
    title="Couldn't sign you in"
    :body="errorText"
    action-label="Back to sign in"
    action-to="/login"
  />

  <AuthLayout v-else>
    <template v-if="view === 'loading'">
      <p style="font-size: 15px; color: var(--paper-ink-2)">Finishing sign-in…</p>
    </template>

    <template v-else-if="view === 'link'">
      <h1 class="auth-h1">
        Confirm it's you
      </h1>
      <p class="auth-sub">
        {{ email }} already has an account. Enter its password once to link Google — this is
        never done automatically.
      </p>
      <form class="mt-8 flex flex-col gap-4" novalidate @submit.prevent="submitLink">
        <div>
          <label for="link-password" class="auth-label">Password</label>
          <input
            id="link-password"
            v-model="password"
            class="auth-input"
            style="margin-top: 6px"
            :class="{ 'is-invalid': !!passwordError }"
            type="password"
            autocomplete="current-password"
          />
          <div class="auth-error" :class="{ open: !!passwordError }" aria-live="polite">
            <span>{{ passwordError }}</span>
          </div>
        </div>
        <AuthButton type="submit" :loading="submitting">Link and sign in</AuthButton>
      </form>
      <router-link to="/login" class="auth-link mt-8 inline-block">Back to sign in</router-link>
    </template>

    <template v-else>
      <h1 class="auth-h1">
        Two-step verification
      </h1>
      <p class="auth-sub">
        {{
          view === "totp"
            ? "Enter the 6-digit code from your authenticator app."
            : "Enter the 6-digit code we emailed you."
        }}
      </p>
      <form class="mt-8" novalidate @submit.prevent="submitChallenge">
        <OtpInput v-model="code" :invalid="!!codeError" :disabled="submitting" />
        <div class="auth-error" :class="{ open: !!codeError }" aria-live="polite">
          <span>{{ codeError }}</span>
        </div>
        <AuthButton type="submit" class="mt-6" :loading="submitting">Verify</AuthButton>
      </form>
    </template>
  </AuthLayout>
</template>
