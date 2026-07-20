<script setup>
import { ref } from "vue";
import { toast } from "vue-sonner";
import { Bell, BellOff, PauseCircle } from "@lucide/vue";
import Card from "@/components/ui/Card.vue";
import CardHeader from "@/components/ui/CardHeader.vue";
import CardTitle from "@/components/ui/CardTitle.vue";
import CardDescription from "@/components/ui/CardDescription.vue";
import CardContent from "@/components/ui/CardContent.vue";
import Button from "@/components/ui/Button.vue";
import SecretField from "@/components/ui/SecretField.vue";
import TestConnectionButton from "@/components/settings/TestConnectionButton.vue";
import { saveSettings } from "@/composables/useSettings";
import { apiErrorMessage } from "@/lib/api-client";
import { cn } from "@/lib/utils";

const props = defineProps({ settings: { type: Object, required: true } });
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

// Global control over automated WhatsApp sends. A dashboard toggle rather
// than a WhatsApp text command — OpenClaw only supports Gmail Pub/Sub
// webhooks, it has no way to forward your replies back to this app.
const ALERT_MODES = [
  {
    value: "normal",
    label: "Normal",
    icon: Bell,
    description: "Fresh lead cards and reminders both send as usual.",
  },
  {
    value: "paused",
    label: "Paused",
    icon: PauseCircle,
    description: "Fresh lead cards still send. All reminders (45/90-min and follow-ups) stop.",
  },
  {
    value: "muted",
    label: "Muted",
    icon: BellOff,
    description: "Nothing sends — no new lead cards, no reminders. For focus time or sleep.",
  },
];

const settingMode = ref(null);

async function setMode(mode) {
  if (mode === props.settings.whatsapp_alert_mode) return;
  settingMode.value = mode;
  try {
    await saveSettings({ whatsapp_alert_mode: mode });
    toast.success(`WhatsApp alerts set to ${mode}.`);
    emit("saved");
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not update WhatsApp alert mode."));
  } finally {
    settingMode.value = null;
  }
}
</script>

<template>
  <div class="space-y-4">
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

    <Card>
      <CardHeader>
        <div>
          <CardTitle>Reminders &amp; alerts</CardTitle>
          <CardDescription class="mt-1">
            Only two things are ever automated on WhatsApp: a brand new lead card, and up to two
            reminders per lead. This controls both at once.
          </CardDescription>
        </div>
      </CardHeader>
      <CardContent>
        <div class="grid gap-2 sm:grid-cols-3">
          <button
            v-for="mode in ALERT_MODES"
            :key="mode.value"
            type="button"
            :disabled="settingMode !== null"
            @click="setMode(mode.value)"
            :class="
              cn(
                'flex flex-col items-start gap-1.5 rounded-md border p-3 text-left transition-colors disabled:opacity-60',
                settings.whatsapp_alert_mode === mode.value
                  ? mode.value === 'muted'
                    ? 'border-danger-border bg-danger-bg'
                    : mode.value === 'paused'
                      ? 'border-warning-border bg-warning-bg'
                      : 'border-primary bg-primary-tint'
                  : 'border-border hover:bg-black/5',
              )
            "
          >
            <span class="flex items-center gap-1.5 text-sm font-semibold text-text-primary">
              <component :is="mode.icon" class="h-3.5 w-3.5" />
              {{ mode.label }}
            </span>
            <span class="text-xs text-text-tertiary">{{ mode.description }}</span>
          </button>
        </div>
      </CardContent>
    </Card>
  </div>
</template>
