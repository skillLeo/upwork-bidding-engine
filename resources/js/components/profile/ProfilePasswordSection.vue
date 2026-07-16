<script setup>
import { ref } from "vue";
import { toast } from "vue-sonner";
import Card from "@/components/ui/Card.vue";
import CardHeader from "@/components/ui/CardHeader.vue";
import CardTitle from "@/components/ui/CardTitle.vue";
import CardContent from "@/components/ui/CardContent.vue";
import CardDescription from "@/components/ui/CardDescription.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
import FieldError from "@/components/ui/FieldError.vue";
import { apiClient, apiErrorMessage } from "@/lib/api-client";

const currentPassword = ref("");
const password = ref("");
const passwordConfirmation = ref("");
const errors = ref({});
const saving = ref(false);

async function handleSave() {
  errors.value = {};
  saving.value = true;
  try {
    await apiClient.put("/profile/password", {
      current_password: currentPassword.value,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    });
    currentPassword.value = "";
    password.value = "";
    passwordConfirmation.value = "";
    toast.success("Password updated. Other signed-in devices have been logged out.");
  } catch (error) {
    errors.value = error.response?.data?.errors
      ? Object.fromEntries(Object.entries(error.response.data.errors).map(([k, v]) => [k, v[0]]))
      : {};
    toast.error(apiErrorMessage(error, "Could not update password."));
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <Card>
    <CardHeader>
      <CardTitle>Password</CardTitle>
    </CardHeader>
    <CardContent class="space-y-4">
      <CardDescription>Changing your password signs you out on every other device.</CardDescription>
      <div>
        <Label>Current password</Label>
        <Input type="password" autocomplete="current-password" v-model="currentPassword" />
        <FieldError :text="errors.current_password" />
      </div>
      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <Label>New password</Label>
          <Input type="password" autocomplete="new-password" v-model="password" />
          <FieldError :text="errors.password" />
        </div>
        <div>
          <Label>Confirm new password</Label>
          <Input type="password" autocomplete="new-password" v-model="passwordConfirmation" />
        </div>
      </div>
      <div class="flex justify-end">
        <Button @click="handleSave" :loading="saving">Update password</Button>
      </div>
    </CardContent>
  </Card>
</template>
