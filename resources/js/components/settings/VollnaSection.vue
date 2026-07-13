<script setup>
import { ref } from "vue";
import { toast } from "vue-sonner";
import Card from "@/components/ui/Card.vue";
import CardHeader from "@/components/ui/CardHeader.vue";
import CardTitle from "@/components/ui/CardTitle.vue";
import CardContent from "@/components/ui/CardContent.vue";
import Button from "@/components/ui/Button.vue";
import SecretField from "@/components/ui/SecretField.vue";
import TestConnectionButton from "@/components/settings/TestConnectionButton.vue";
import { saveSettings } from "@/composables/useSettings";
import { apiErrorMessage } from "@/lib/api-client";

const props = defineProps({ settings: { type: Object, required: true } });
const emit = defineEmits(["saved"]);

const secret = ref("");
const saving = ref(false);

async function handleSave() {
  saving.value = true;
  try {
    await saveSettings({ vollna_webhook_secret: secret.value });
    secret.value = "";
    toast.success("Vollna settings saved.");
    emit("saved");
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not save Vollna settings."));
  } finally {
    saving.value = false;
  }
}

const apiBase = import.meta.env.VITE_API_URL ?? "";
const webhookUrl = `${apiBase}/vollna-hook`;
</script>

<template>
  <Card>
    <CardHeader>
      <CardTitle>Vollna</CardTitle>
      <TestConnectionButton service="vollna" />
    </CardHeader>
    <CardContent class="space-y-4">
      <SecretField
        label="Webhook secret"
        :is-set="settings.vollna_webhook_secret.is_set"
        :masked="settings.vollna_webhook_secret.masked"
        v-model="secret"
        hint="Vollna must send this back in the X-Vollna-Secret header on every job."
      />
      <div class="rounded-md bg-neutral-bg p-3">
        <p class="text-xs font-medium text-text-tertiary">Point Vollna's webhook at</p>
        <p class="mt-1 font-mono text-xs break-all text-text-primary">{{ webhookUrl }}</p>
      </div>
      <div class="flex justify-end">
        <Button @click="handleSave" :loading="saving">Save Vollna settings</Button>
      </div>
    </CardContent>
  </Card>
</template>
