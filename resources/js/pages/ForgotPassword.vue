<script setup>
import { ref } from "vue";
import { apiClient, apiErrorMessage } from "@/lib/api-client";
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
  <div class="flex min-h-screen flex-1 flex-col items-center justify-center bg-bg px-4 py-12">
    <div class="mb-8 flex items-center gap-2 text-2xl font-bold text-primary">
      <span class="flex h-10 w-10 items-center justify-center rounded-md bg-primary text-base font-bold text-white">
        SL
      </span>
      SkillLeo
    </div>

    <div class="w-full max-w-sm rounded-card border border-border bg-surface p-8 shadow-card">
      <template v-if="sent">
        <h1 class="text-xl font-semibold text-text-primary">Check your email</h1>
        <p class="mt-2 text-sm text-text-secondary">
          If <span class="font-medium text-text-primary">{{ email }}</span> is registered, a
          password reset link is on its way.
        </p>
        <router-link
          to="/login"
          class="mt-6 inline-block text-sm font-medium text-primary hover:underline"
        >
          Back to sign in
        </router-link>
      </template>

      <template v-else>
        <h1 class="text-xl font-semibold text-text-primary">Forgot your password?</h1>
        <p class="mt-1 text-sm text-text-secondary">We'll email you a link to reset it.</p>

        <form @submit.prevent="onSubmit" class="mt-6 space-y-4" novalidate>
          <div>
            <Label for="email">Email</Label>
            <Input
              id="email"
              type="email"
              autocomplete="email"
              placeholder="you@company.com"
              v-model="email"
            />
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
          Back to sign in
        </router-link>
      </template>
    </div>
  </div>
</template>
