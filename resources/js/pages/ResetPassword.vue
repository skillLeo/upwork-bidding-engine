<script setup>
import { ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { toast } from "vue-sonner";
import { apiClient, apiErrorMessage } from "@/lib/api-client";
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
  <div class="flex min-h-screen flex-1 flex-col items-center justify-center bg-bg px-4 py-12">
    <div class="mb-8 flex items-center gap-2 text-2xl font-bold text-primary">
      <span class="flex h-10 w-10 items-center justify-center rounded-md bg-primary text-base font-bold text-white">
        SL
      </span>
      SkillLeo
    </div>

    <div class="w-full max-w-sm rounded-card border border-border bg-surface p-8 shadow-card">
      <h1 class="text-xl font-semibold text-text-primary">Reset your password</h1>
      <p class="mt-1 text-sm text-text-secondary">Choose a new password for {{ email }}.</p>

      <form @submit.prevent="onSubmit" class="mt-6 space-y-4" novalidate>
        <div>
          <Label for="email">Email</Label>
          <Input id="email" type="email" autocomplete="email" v-model="email" />
          <FieldError :text="errors.email" />
        </div>
        <div>
          <Label for="password">New password</Label>
          <Input
            id="password"
            type="password"
            autocomplete="new-password"
            v-model="password"
          />
          <FieldError :text="errors.password" />
        </div>
        <div>
          <Label for="password_confirmation">Confirm new password</Label>
          <Input
            id="password_confirmation"
            type="password"
            autocomplete="new-password"
            v-model="passwordConfirmation"
          />
        </div>
        <Button type="submit" class="w-full" size="lg" :loading="submitting">
          Reset password
        </Button>
      </form>
    </div>
  </div>
</template>
