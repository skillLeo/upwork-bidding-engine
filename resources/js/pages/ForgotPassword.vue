<script setup>
import { ref } from "vue";
import { Mail } from "@lucide/vue";
import { apiClient, apiErrorMessage } from "@/lib/api-client";
import AuthShell from "@/components/layout/AuthShell.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
import FieldError from "@/components/ui/FieldError.vue";

const email = ref("");
const errors = ref({});
const submitting = ref(false);
const sent = ref(false);

async function onSubmit() {
  errors.value = {};
  if (!email.value || !/^\S+@\S+\.\S+$/.test(email.value)) {
    errors.value = { email: "Enter a valid email address." };
    return;
  }

  submitting.value = true;
  try {
    await apiClient.post("/auth/forgot-password", { email: email.value });
    sent.value = true;
  } catch (error) {
    errors.value = { email: apiErrorMessage(error, "Could not send the reset link.") };
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <AuthShell tagline="Score, draft, and track Upwork proposals from one dashboard.">
    <template v-if="sent">
      <h1 class="text-2xl font-semibold text-text-primary">Check your email</h1>
      <p class="mt-1.5 text-sm text-text-secondary">
        If <span class="font-medium text-text-primary">{{ email }}</span> is registered, a
        password reset link is on its way.
      </p>
      <router-link
        to="/login"
        class="mt-6 inline-block text-sm font-medium text-primary hover:underline"
      >
        ← Back to sign in
      </router-link>
    </template>

    <template v-else>
      <h1 class="text-2xl font-semibold text-text-primary">Forgot your password?</h1>
      <p class="mt-1.5 text-sm text-text-secondary">We'll email you a link to reset it.</p>

      <form @submit.prevent="onSubmit" class="mt-6 space-y-4" novalidate>
        <div>
          <Label for="email">Email</Label>
          <div class="relative">
            <Mail class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-text-tertiary" />
            <Input
              id="email"
              type="email"
              autocomplete="email"
              placeholder="you@company.com"
              v-model="email"
              class="pl-9"
            />
          </div>
          <FieldError :text="errors.email" />
        </div>
        <Button type="submit" class="w-full" size="lg" :loading="submitting">
          Send reset link
        </Button>
      </form>

      <router-link
        to="/login"
        class="mt-6 inline-block text-sm font-medium text-primary hover:underline"
      >
        ← Back to sign in
      </router-link>
    </template>
  </AuthShell>
</template>
