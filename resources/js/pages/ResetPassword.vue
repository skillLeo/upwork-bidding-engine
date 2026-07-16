<script setup>
import { ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { toast } from "vue-sonner";
import { Lock, Mail } from "@lucide/vue";
import { apiClient, apiErrorMessage } from "@/lib/api-client";
import AuthShell from "@/components/layout/AuthShell.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
import FieldError from "@/components/ui/FieldError.vue";

const route = useRoute();
const router = useRouter();

const token = String(route.query.token ?? "");
const email = ref(String(route.query.email ?? ""));
const password = ref("");
const passwordConfirmation = ref("");
const errors = ref({});
const submitting = ref(false);

async function onSubmit() {
  errors.value = {};
  submitting.value = true;
  try {
    await apiClient.post("/auth/reset-password", {
      token,
      email: email.value,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    });
    toast.success("Password reset — sign in with your new password.");
    router.replace({ name: "login" });
  } catch (error) {
    errors.value = error.response?.data?.errors
      ? Object.fromEntries(Object.entries(error.response.data.errors).map(([k, v]) => [k, v[0]]))
      : {};
    toast.error(apiErrorMessage(error, "Could not reset the password."));
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <AuthShell tagline="Score, draft, and track Upwork proposals from one dashboard.">
    <h1 class="text-2xl font-semibold text-text-primary">Reset your password</h1>
    <p class="mt-1.5 text-sm text-text-secondary">Choose a new password for {{ email }}.</p>

    <form @submit.prevent="onSubmit" class="mt-6 space-y-4" novalidate>
      <div>
        <Label for="email">Email</Label>
        <div class="relative">
          <Mail class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-text-tertiary" />
          <Input id="email" type="email" autocomplete="email" v-model="email" class="pl-9" />
        </div>
        <FieldError :text="errors.email" />
      </div>
      <div>
        <Label for="password">New password</Label>
        <div class="relative">
          <Lock class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-text-tertiary" />
          <Input
            id="password"
            type="password"
            autocomplete="new-password"
            v-model="password"
            class="pl-9"
          />
        </div>
        <FieldError :text="errors.password" />
      </div>
      <div>
        <Label for="password_confirmation">Confirm new password</Label>
        <div class="relative">
          <Lock class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-text-tertiary" />
          <Input
            id="password_confirmation"
            type="password"
            autocomplete="new-password"
            v-model="passwordConfirmation"
            class="pl-9"
          />
        </div>
      </div>
      <Button type="submit" class="w-full" size="lg" :loading="submitting">
        Reset password
      </Button>
    </form>
  </AuthShell>
</template>
