<script setup>
import { computed, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { apiClient, apiErrorMessage } from "@/lib/api-client";
import AuthLayout from "@/components/auth/AuthLayout.vue";
import AuthDeadEnd from "@/components/auth/AuthDeadEnd.vue";
import AuthField from "@/components/auth/AuthField.vue";
import AuthButton from "@/components/auth/AuthButton.vue";

const route = useRoute();
const router = useRouter();

const token = String(route.query.token ?? "");
const email = String(route.query.email ?? "");
const password = ref("");
const confirm = ref("");
const errors = ref({});
const submitting = ref(false);
const dead = ref(!token); // no token at all → straight to the dead-end state

// A 0–4 score drives the 4-segment bar. No coloured word — the bar is the
// whole indicator.
const strength = computed(() => {
  const p = password.value;
  if (!p) return 0;
  let s = 0;
  if (p.length >= 8) s += 1;
  if (/[a-z]/.test(p) && /[A-Z]/.test(p)) s += 1;
  if (/\d/.test(p)) s += 1;
  if (/[^A-Za-z0-9]/.test(p) || p.length >= 12) s += 1;
  return s;
});

async function onSubmit() {
  errors.value = {};
  if (password.value.length < 8) {
    errors.value = { password: "Use at least 8 characters." };
    return;
  }
  if (password.value !== confirm.value) {
    errors.value = { confirm: "Both passwords must match." };
    return;
  }
  submitting.value = true;
  try {
    await apiClient.post("/auth/reset-password", {
      token,
      email,
      password: password.value,
      password_confirmation: confirm.value,
    });
    router.replace({ name: "login", query: { reset: "1" } });
  } catch (error) {
    const status = error.response?.status;
    const fieldErrors = error.response?.data?.errors;
    // A bad/expired token surfaces as a validation error keyed on email —
    // that is the dead-link case, so send them there rather than showing a
    // confusing inline message under a field they cannot fix.
    if (status === 422 && fieldErrors?.email) {
      dead.value = true;
      return;
    }
    if (fieldErrors?.password) {
      errors.value = { password: fieldErrors.password[0] };
    } else {
      errors.value = { password: apiErrorMessage(error, "Could not reset the password.") };
    }
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <AuthDeadEnd
    v-if="dead"
    title="This reset link has expired"
    body="Password reset links are valid for 60 minutes. Request a new one to continue."
    action-label="Request a new link"
    action-to="/forgot-password"
  />

  <AuthLayout v-else>
    <h1 class="auth-h1">
      Set a new password
    </h1>
    <p class="auth-sub">
      Choose a new password for {{ email }}.
    </p>

    <form class="mt-8 flex flex-col gap-4" novalidate @submit.prevent="onSubmit">
      <div>
        <AuthField
          id="password"
          v-model="password"
          label="New password"
          type="password"
          autocomplete="new-password"
          :error="errors.password"
        />
        <!-- 4-segment strength bar: empty is the paper separator, filled is
             --signal. No colour-coded word. -->
        <div
          class="mt-2 flex gap-2"
          role="meter"
          aria-label="Password strength"
          :aria-valuenow="strength"
          aria-valuemin="0"
          aria-valuemax="4"
        >
          <span v-for="i in 4" :key="i" class="auth-meter" :class="{ on: i <= strength }"></span>
        </div>
      </div>

      <AuthField
        id="confirm"
        v-model="confirm"
        label="Confirm new password"
        type="password"
        autocomplete="new-password"
        :error="errors.confirm"
      />

      <AuthButton type="submit" :loading="submitting">Set password</AuthButton>
    </form>

    <router-link to="/login" class="auth-link mt-8 inline-block">Back to sign in</router-link>
  </AuthLayout>
</template>
