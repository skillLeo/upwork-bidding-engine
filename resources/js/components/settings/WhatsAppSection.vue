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

const bidderNumber = ref("");
const saving = ref(false);

async function handleSave() {
  saving.value = true;
  try {
    await saveSettings({
      bidder_whatsapp: bidderNumber.value,
    });
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
      <CardTitle>WhatsApp (via OpenClaw)</CardTitle>
      <TestConnectionButton service="whatsapp" />
    </CardHeader>
    <CardContent class="space-y-4">
      <SecretField
        label="Bidder's WhatsApp number"
        :is-set="settings.bidder_whatsapp.is_set"
        :masked="settings.bidder_whatsapp.masked"
        v-model="bidderNumber"
        hint="E.164 format, e.g. +15551234567. Test connection sends a real WhatsApp message to this number."
      />
      <div class="flex justify-end">
        <Button @click="handleSave" :loading="saving">Save WhatsApp settings</Button>
      </div>
    </CardContent>
  </Card>
</template>
