<script setup>
import { onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { toast } from "vue-sonner";
import AuthShell from "@/components/layout/AuthShell.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
import FieldError from "@/components/ui/FieldError.vue";
import { apiClient, apiErrorMessage } from "@/lib/api-client";
import { useAuthStore } from "@/stores/auth";

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const state = ref("loading"); // loading | link | totp | otp | error
const error = ref("");
const email = ref("");
const linkToken = ref("");
const password = ref("");
const code = ref("");
const challenge = ref("");
const submitting = ref(false);

function landing() {
  return auth.takeLastRoute() || "/leads";
}

async function finishWithToken(token) {
  try {
    const res = await apiClient.get("/me", { headers: { Authorization: `Bearer ${token}` } });
    auth.setAuth(token, res.data.data, true);
    toast.success("Welcome back.");
    router.replace(landing());
  } catch (err) {
    error.value = apiErrorMessage(err, "Could not complete sign-in.");
    state.value = "error";
  }
}

async function submitLink() {
  if (!password.value) return;
  submitting.value = true;
  try {
    const res = await apiClient.post("/auth/google/link", {
      link_token: linkToken.value,
      password: password.value,
    });
    if (res.data.data.requires_totp) {
      challenge.value = res.data.data.challenge;
      state.value = "totp";
      return;
    }
    if (res.data.data.requires_otp) {
      challenge.value = res.data.data.challenge;
      state.value = "otp";
      return;
    }
    await finishWithToken(res.data.data.token);
  } catch (err) {
    error.value = apiErrorMessage(err, "That password is incorrect.");
  } finally {
    submitting.value = false;
  }
}

async function submitChallenge() {
  if (!code.value) return;
  submitting.value = true;
  try {
    const endpoint = state.value === "totp" ? "/auth/verify-totp" : "/auth/verify-otp";
    const res = await apiClient.post(endpoint, { challenge: challenge.value, code: code.value });
    await finishWithToken(res.data.data.token);
  } catch (err) {
    error.value = apiErrorMessage(err, "That code is incorrect or has expired.");
  } finally {
    submitting.value = false;
  }
}

onMounted(() => {
  const q = route.query;

  if (q.error) {
    error.value = String(q.error);
    state.value = "error";
    return;
  }

  if (q.link_required) {
    linkToken.value = String(q.link_token ?? "");
    email.value = String(q.email ?? "");
    state.value = "link";
    return;
  }

  if (q.requires_totp) {
    challenge.value = String(q.challenge ?? "");
    state.value = "totp";
    return;
  }

  if (q.requires_otp) {
    challenge.value = String(q.challenge ?? "");
    state.value = "otp";
    return;
  }

  if (q.token) {
    finishWithToken(String(q.token));
    return;
  }

  error.value = "Something went wrong finishing Google sign-in.";
  state.value = "error";
});
</script>

<template>
  <AuthShell>
    <template v-if="state === 'loading'">
      <p class="text-center text-sm text-text-secondary">Finishing sign-in…</p>
    </template>

    <template v-else-if="state === 'link'">
      <h1 class="text-2xl font-semibold text-text-primary">Confirm it's you</h1>
      <p class="mt-1.5 text-sm text-text-secondary">
        <span class="font-medium text-text-primary">{{ email }}</span> already has an account. Enter
        its password to link your Google sign-in — this is never done automatically.
      </p>
      <form @submit.prevent="submitLink" class="mt-6 space-y-4" novalidate>
        <div>
          <Label for="link-password">Password</Label>
          <Input id="link-password" type="password" autocomplete="current-password" v-model="password" />
          <FieldError :text="error" />
        </div>
        <Button type="submit" class="w-full" size="lg" :loading="submitting">Link and sign in</Button>
      </form>
      <router-link to="/login" class="mt-4 block text-sm font-medium text-text-secondary hover:text-primary">← Back to sign in</router-link>
    </template>

    <template v-else-if="state === 'totp' || state === 'otp'">
      <h1 class="text-2xl font-semibold text-text-primary">Enter your code</h1>
      <p class="mt-1.5 text-sm text-text-secondary">
        {{ state === "totp" ? "The 6-digit code from your authenticator app (or a recovery code)." : "The 6-digit code we emailed you." }}
      </p>
      <form @submit.prevent="submitChallenge" class="mt-6 space-y-4" novalidate>
        <div>
          <Label for="google-code">Code</Label>
          <Input id="google-code" autocomplete="one-time-code" v-model="code" class="text-center text-lg tracking-[0.3em]" />
          <FieldError :text="error" />
        </div>
        <Button type="submit" class="w-full" size="lg" :loading="submitting">Verify</Button>
      </form>
    </template>

    <template v-else>
      <h1 class="text-2xl font-semibold text-text-primary">Couldn't sign you in</h1>
      <p class="mt-1.5 text-sm text-text-secondary">{{ error }}</p>
      <router-link to="/login" class="mt-6 inline-block text-sm font-medium text-primary hover:underline">← Back to sign in</router-link>
    </template>
  </AuthShell>
</template>
