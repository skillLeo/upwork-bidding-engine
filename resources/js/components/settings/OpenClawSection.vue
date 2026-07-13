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

defineProps({ settings: { type: Object, required: true } });
const emit = defineEmits(["saved"]);

const url = ref("");
const token = ref("");
const saving = ref(false);

async function handleSave() {
  saving.value = true;
  try {
    await saveSettings({ openclaw_url: url.value, openclaw_token: token.value });
    url.value = "";
    token.value = "";
    toast.success("OpenClaw settings saved.");
    emit("saved");
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not save OpenClaw settings."));
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <Card>
    <CardHeader>
      <CardTitle>OpenClaw</CardTitle>
      <TestConnectionButton service="openclaw" />
    </CardHeader>
    <CardContent class="space-y-4">
      <SecretField
        label="OpenClaw URL"
        :is-set="settings.openclaw_url.is_set"
        :masked="settings.openclaw_url.masked"
        v-model="url"
        hint="Base URL, e.g. https://openclaw.example.com"
      />
      <SecretField
        label="OpenClaw token"
        :is-set="settings.openclaw_token.is_set"
        :masked="settings.openclaw_token.masked"
        v-model="token"
        hint="Sent as a Bearer token on every scoring/drafting request."
      />
      <div class="flex justify-end">
        <Button @click="handleSave" :loading="saving">Save OpenClaw settings</Button>
      </div>
    </CardContent>
  </Card>
</template>
