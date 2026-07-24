<script setup>
import { ref } from "vue";
import { apiClient } from "@/lib/api-client";
import AuthLayout from "@/components/auth/AuthLayout.vue";
import AuthField from "@/components/auth/AuthField.vue";
import AuthButton from "@/components/auth/AuthButton.vue";

const email = ref("");
const error = ref("");
const submitting = ref(false);
const sent = ref(false);

async function onSubmit() {
  error.value = "";
  if (!email.value || !/^\S+@\S+\.\S+$/.test(email.value)) {
    error.value = "Enter a valid email address.";
    return;
  }
  submitting.value = true;
  try {
    await apiClient.post("/auth/forgot-password", { email: email.value });
    // Same confirmation whether or not the address exists — the API never
    // reveals which, and neither does this screen.
    sent.value = true;
  } catch {
    // Even a failure lands on the neutral confirmation: an error here would
    // itself leak whether the address is known.
    sent.value = true;
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <AuthLayout>
    <template v-if="sent">
      <h1 class="auth-h1">
        Check your email
      </h1>
      <p class="auth-sub">
        If an account exists for {{ email }}, a link to reset the password is on its way. It
        expires in 60 minutes.
      </p>
      <router-link to="/login" class="auth-link mt-8 inline-block">Back to sign in</router-link>
    </template>

    <template v-else>
      <h1 class="auth-h1">
        Reset your password
      </h1>
      <p class="auth-sub">
        Enter your email and we'll send a link to set a new one.
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
          :error="error"
        />
        <AuthButton type="submit" :loading="submitting">Send reset link</AuthButton>
      </form>

      <router-link to="/login" class="auth-link mt-8 inline-block">Back to sign in</router-link>
    </template>
  </AuthLayout>
</template>
