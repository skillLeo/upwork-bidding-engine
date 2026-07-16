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
import SecretField from "@/components/ui/SecretField.vue";
import TestConnectionButton from "@/components/settings/TestConnectionButton.vue";
import { saveSettings } from "@/composables/useSettings";
import { apiErrorMessage } from "@/lib/api-client";

const props = defineProps({ settings: { type: Object, required: true } });
const emit = defineEmits(["saved"]);

const form = ref({
  mail_host: props.settings.mail_host ?? "",
  mail_port: props.settings.mail_port ?? 587,
  mail_encryption: props.settings.mail_encryption ?? "tls",
  mail_from_address: props.settings.mail_from_address ?? "",
  mail_from_name: props.settings.mail_from_name ?? "",
});
const username = ref("");
const password = ref("");
const saving = ref(false);

async function handleSave() {
  saving.value = true;
  try {
    await saveSettings({
      ...form.value,
      mail_username: username.value,
      mail_password: password.value,
    });
    username.value = "";
    password.value = "";
    toast.success("Mail settings saved.");
    emit("saved");
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not save mail settings."));
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <Card>
    <CardHeader>
      <CardTitle>Mail (SMTP)</CardTitle>
      <TestConnectionButton service="mail" />
    </CardHeader>
    <CardContent class="space-y-4">
      <CardDescription>
        Used for password-reset links and two-factor login codes. Not stored in `.env` — change it
        here any time, no redeploy needed.
      </CardDescription>
      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <Label>SMTP host</Label>
          <Input v-model="form.mail_host" placeholder="smtp.hostinger.com" />
        </div>
        <div>
          <Label>Port</Label>
          <Input type="number" v-model.number="form.mail_port" />
        </div>
      </div>
      <SecretField
        label="SMTP username"
        :is-set="settings.mail_username.is_set"
        :masked="settings.mail_username.masked"
        v-model="username"
      />
      <SecretField
        label="SMTP password"
        :is-set="settings.mail_password.is_set"
        :masked="settings.mail_password.masked"
        v-model="password"
      />
      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <Label>Encryption</Label>
          <select
            v-model="form.mail_encryption"
            class="h-10 w-full rounded-md border border-border-strong bg-white px-3 text-sm text-text-secondary focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
          >
            <option value="tls">TLS</option>
            <option value="ssl">SSL</option>
            <option value="">None</option>
          </select>
        </div>
        <div>
          <Label>From name</Label>
          <Input v-model="form.mail_from_name" />
        </div>
      </div>
      <div>
        <Label>From address</Label>
        <Input type="email" v-model="form.mail_from_address" placeholder="noreply@upwork.skillleo.com" />
      </div>
      <div class="flex justify-end">
        <Button @click="handleSave" :loading="saving">Save mail settings</Button>
      </div>
    </CardContent>
  </Card>
</template>
