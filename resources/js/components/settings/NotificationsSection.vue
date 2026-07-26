<script setup>
import { ref, watch } from "vue";
import { toast } from "vue-sonner";
import { Bell, BellOff, PauseCircle } from "@lucide/vue";
import Card from "@/components/ui/Card.vue";
import CardHeader from "@/components/ui/CardHeader.vue";
import CardTitle from "@/components/ui/CardTitle.vue";
import CardDescription from "@/components/ui/CardDescription.vue";
import CardContent from "@/components/ui/CardContent.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
import FieldHint from "@/components/ui/FieldHint.vue";
import SecretField from "@/components/ui/SecretField.vue";
import TestConnectionButton from "@/components/settings/TestConnectionButton.vue";
import BrowserAlertsSection from "@/components/settings/BrowserAlertsSection.vue";
import { saveSettings } from "@/composables/useSettings";
import { apiErrorMessage } from "@/lib/api-client";
import { cn } from "@/lib/utils";

// Everything that decides whether, when and how this app interrupts you —
// in one place. Previously split across "Bidding rules" (thresholds),
// "Browser alerts" (Web Push) and "WhatsApp" (number + pause/mute), which
// meant three tabs to check before trusting that alerts were on.
const props = defineProps({ settings: { type: Object, required: true } });
const emit = defineEmits(["saved"]);

const form = ref({
  notify_score_min: props.settings.notify_score_min,
  notification_freshness_hours: props.settings.notification_freshness_hours,
  quiet_hours_start: props.settings.quiet_hours_start,
  quiet_hours_end: props.settings.quiet_hours_end,
});

watch(
  () => props.settings,
  (value) => {
    form.value = {
      notify_score_min: value.notify_score_min,
      notification_freshness_hours: value.notification_freshness_hours,
      quiet_hours_start: value.quiet_hours_start,
      quiet_hours_end: value.quiet_hours_end,
    };
  },
);

const savingRules = ref(false);

async function saveRules() {
  savingRules.value = true;
  try {
    await saveSettings({ ...form.value });
    toast.success("Alert rules saved.");
    emit("saved");
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not save alert rules."));
  } finally {
    savingRules.value = false;
  }
}

// Global quiet switch. Applies to EVERY channel, not just WhatsApp — the
// setting keeps its original key for compatibility.
const ALERT_MODES = [
  {
    value: "normal",
    label: "Normal",
    icon: Bell,
    description: "New lead alerts and reminders both fire as usual.",
  },
  {
    value: "paused",
    label: "Paused",
    icon: PauseCircle,
    description: "New lead alerts still fire. All reminders stop.",
  },
  {
    value: "muted",
    label: "Muted",
    icon: BellOff,
    description: "Nothing interrupts you. Leads still appear on the dashboard.",
  },
];

const settingMode = ref(null);

async function setMode(mode) {
  if (mode === props.settings.whatsapp_alert_mode) return;
  settingMode.value = mode;
  try {
    await saveSettings({ whatsapp_alert_mode: mode });
    toast.success(`Alerts set to ${mode}.`);
    emit("saved");
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not update the alert mode."));
  } finally {
    settingMode.value = null;
  }
}

const bidderNumber = ref("");
const savingWhatsapp = ref(false);

async function saveWhatsapp() {
  savingWhatsapp.value = true;
  try {
    await saveSettings({ bidder_whatsapp: bidderNumber.value });
    bidderNumber.value = "";
    toast.success("WhatsApp number saved.");
    emit("saved");
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not save the WhatsApp number."));
  } finally {
    savingWhatsapp.value = false;
  }
}
</script>

<template>
  <div class="space-y-4">
    <!-- 1. When to interrupt -->
    <Card>
      <CardHeader>
        <div>
          <CardTitle>When to alert me</CardTitle>
          <CardDescription class="mt-1">
            Leads below the bar are still scored and listed — they just don't interrupt you.
          </CardDescription>
        </div>
      </CardHeader>
      <CardContent class="space-y-4">
        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <Label>Alert me at score</Label>
            <Input type="number" min="1" max="10" v-model.number="form.notify_score_min" />
            <FieldHint>1–10. Also the bar for reminders.</FieldHint>
          </div>
          <div>
            <Label>Alert freshness (hours)</Label>
            <Input type="number" min="0" max="168" v-model.number="form.notification_freshness_hours" />
            <FieldHint>Older leads stay visible but never ring. 0 disables.</FieldHint>
          </div>
          <div>
            <Label>Quiet hours start</Label>
            <Input type="number" min="0" max="23" v-model.number="form.quiet_hours_start" />
            <FieldHint>Hour, Pakistan time.</FieldHint>
          </div>
          <div>
            <Label>Quiet hours end</Label>
            <Input type="number" min="0" max="23" v-model.number="form.quiet_hours_end" />
            <FieldHint>Reminders pause in this window. Same value both = off.</FieldHint>
          </div>
        </div>
        <div class="flex justify-end">
          <Button @click="saveRules" :loading="savingRules">Save alert rules</Button>
        </div>
      </CardContent>
    </Card>

    <!-- 2. The global quiet switch -->
    <Card>
      <CardHeader>
        <div>
          <CardTitle>Alert mode</CardTitle>
          <CardDescription class="mt-1">
            Applies to every channel at once — browser, WhatsApp and email.
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

    <!-- 3. Browser / phone push — the primary channel -->
    <BrowserAlertsSection />

    <!-- 4. WhatsApp — secondary, optional -->
    <Card>
      <CardHeader>
        <div>
          <CardTitle>WhatsApp</CardTitle>
          <CardDescription class="mt-1">
            A secondary copy of each alert. Optional — browser alerts keep working without it.
          </CardDescription>
        </div>
        <TestConnectionButton service="whatsapp" />
      </CardHeader>
      <CardContent class="space-y-4">
        <SecretField
          label="Your WhatsApp number"
          :is-set="settings.bidder_whatsapp.is_set"
          :masked="settings.bidder_whatsapp.masked"
          v-model="bidderNumber"
          hint="E.164 format, e.g. +15551234567. Test connection sends a real message."
        />
        <div class="flex justify-end">
          <Button @click="saveWhatsapp" :loading="savingWhatsapp">Save WhatsApp number</Button>
        </div>
      </CardContent>
    </Card>
  </div>
</template>
