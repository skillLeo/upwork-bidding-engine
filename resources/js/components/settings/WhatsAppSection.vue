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

const token = ref("");
const phoneId = ref("");
const bidderNumber = ref("");
const saving = ref(false);

async function handleSave() {
  saving.value = true;
  try {
    await saveSettings({
      whatsapp_token: token.value,
      whatsapp_phone_id: phoneId.value,
      bidder_whatsapp: bidderNumber.value,
    });
    token.value = "";
    phoneId.value = "";
    bidderNumber.value = "";
    toast.success("WhatsApp settings saved.");
    emit("saved");
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not save WhatsApp settings."));
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <Card>
    <CardHeader>
      <CardTitle>WhatsApp</CardTitle>
      <TestConnectionButton service="whatsapp" />
    </CardHeader>
    <CardContent class="space-y-4">
      <SecretField
        label="Access token"
        :is-set="settings.whatsapp_token.is_set"
        :masked="settings.whatsapp_token.masked"
        v-model="token"
      />
      <SecretField
        label="Phone number ID"
        :is-set="settings.whatsapp_phone_id.is_set"
        :masked="settings.whatsapp_phone_id.masked"
        v-model="phoneId"
      />
      <SecretField
        label="Bidder's WhatsApp number"
        :is-set="settings.bidder_whatsapp.is_set"
        :masked="settings.bidder_whatsapp.masked"
        v-model="bidderNumber"
        hint="E.164 format, e.g. +15551234567"
      />
      <div class="flex justify-end">
        <Button @click="handleSave" :loading="saving">Save WhatsApp settings</Button>
      </div>
    </CardContent>
  </Card>
</template>
