<script setup>
import { ref, computed } from "vue";
import { toast } from "vue-sonner";
import { RefreshCw } from "@lucide/vue";
import Card from "@/components/ui/Card.vue";
import CardHeader from "@/components/ui/CardHeader.vue";
import CardTitle from "@/components/ui/CardTitle.vue";
import CardContent from "@/components/ui/CardContent.vue";
import CardDescription from "@/components/ui/CardDescription.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
import FieldHint from "@/components/ui/FieldHint.vue";
import SecretField from "@/components/ui/SecretField.vue";
import TestConnectionButton from "@/components/settings/TestConnectionButton.vue";
import { saveSettings } from "@/composables/useSettings";
import { apiClient, apiErrorMessage } from "@/lib/api-client";
import { relativeTime } from "@/lib/utils";

const props = defineProps({ settings: { type: Object, required: true } });
const emit = defineEmits(["saved"]);

const secret = ref("");
const apiToken = ref("");
const filterId = ref(props.settings.vollna_filter_id ?? "");
const silenceAlertHours = ref(props.settings.vollna_silence_alert_hours ?? 6);
const saving = ref(false);
const syncing = ref(false);

// Email intake — Vollna put webhooks behind their Agency plan, so the
// free email notifications become the live door.
const gmailAddress = ref(props.settings.gmail_address ?? "");
const gmailAppPassword = ref("");
const imapHost = ref(props.settings.imap_host ?? "imap.gmail.com");
const imapPort = ref(props.settings.imap_port ?? 993);
const imapFolder = ref(props.settings.imap_folder ?? "INBOX");
const senderFilter = ref(props.settings.vollna_sender_filter ?? "");
const pollEnabled = ref(props.settings.imap_poll_enabled ?? true);

async function handleSave() {
  saving.value = true;
  try {
    await saveSettings({
      vollna_webhook_secret: secret.value,
      vollna_api_token: apiToken.value,
      vollna_filter_id: filterId.value,
      vollna_silence_alert_hours: Number(silenceAlertHours.value) || 6,
      gmail_address: gmailAddress.value,
      gmail_app_password: gmailAppPassword.value,
      imap_host: imapHost.value,
      imap_port: Number(imapPort.value) || 993,
      imap_folder: imapFolder.value,
      vollna_sender_filter: senderFilter.value,
      imap_poll_enabled: pollEnabled.value,
    });
    secret.value = "";
    apiToken.value = "";
    gmailAppPassword.value = "";
    toast.success("Vollna settings saved.");
    emit("saved");
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not save Vollna settings."));
  } finally {
    saving.value = false;
  }
}

async function handleSync() {
  syncing.value = true;
  try {
    const res = await apiClient.post("/leads/sync-vollna");
    toast.success(res.data.data.message);
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not start the sync."));
  } finally {
    syncing.value = false;
  }
}

const lastSync = computed(() => props.settings.vollna_last_sync ?? null);

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
      <div>
        <Label>Silence alert (hours)</Label>
        <Input v-model="silenceAlertHours" type="number" min="1" max="72" />
        <FieldHint>
          Get a WhatsApp alert if no webhook delivery arrives for this many hours — catches
          Vollna going quiet before a day of leads is lost.
        </FieldHint>
      </div>
      <div class="flex justify-end">
        <Button @click="handleSave" :loading="saving">Save Vollna settings</Button>
      </div>

      <div class="space-y-4 border-t border-border pt-4">
        <CardDescription>
          Manual sync — mirrors your Vollna filter's current results exactly. Leads that no
          longer match (e.g. after you narrow the filter) are removed; new matches are added.
          Only ever runs when you press the button below.
        </CardDescription>
        <SecretField
          label="API token"
          :is-set="settings.vollna_api_token.is_set"
          :masked="settings.vollna_api_token.masked"
          v-model="apiToken"
          hint="Vollna dashboard → Developers → API Tokens."
        />
        <div>
          <Label>Filter ID</Label>
          <Input v-model="filterId" placeholder="e.g. 40694" />
          <FieldHint>The numeric ID in your filter's dashboard URL.</FieldHint>
        </div>
        <div class="flex items-center justify-between gap-3">
          <p class="text-xs text-text-tertiary">
            <template v-if="lastSync">
              Last sync: {{ lastSync.message }}
              <template v-if="lastSync.finished_at">
                ({{ relativeTime(lastSync.finished_at) }})
              </template>
            </template>
            <template v-else>Never synced yet.</template>
          </p>
          <Button variant="secondary" @click="handleSync" :loading="syncing">
            <RefreshCw class="h-4 w-4" /> Sync now
          </Button>
        </div>
      </div>

      <div class="space-y-4 rounded-md border border-border bg-neutral-bg/50 p-4">
        <div>
          <p class="text-sm font-semibold text-text-primary">Vollna email intake (IMAP)</p>
          <CardDescription class="mt-1">
            Vollna moved webhooks to their Agency plan, but email notifications stay free. This
            reads those emails and feeds them into the same pipeline the webhook used, so
            scoring, proposals, and WhatsApp alerts are unchanged.
          </CardDescription>
        </div>

        <label class="flex items-center gap-2 text-sm text-text-secondary select-none">
          <input
            type="checkbox"
            v-model="pollEnabled"
            class="h-4 w-4 rounded border-border-strong text-primary focus:ring-primary/20"
          />
          Email intake enabled
        </label>

        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <Label>Gmail address</Label>
            <Input v-model="gmailAddress" placeholder="skillleopvt@gmail.com" />
          </div>
          <div>
            <Label>Vollna sender filter</Label>
            <Input v-model="senderFilter" placeholder="vollna.com" />
            <FieldHint>Only mail from this sender is read.</FieldHint>
          </div>
        </div>

        <SecretField
          label="Gmail App Password"
          :is-set="settings.gmail_app_password?.is_set ?? false"
          :masked="settings.gmail_app_password?.masked ?? ''"
          v-model="gmailAppPassword"
          hint="16-character App Password, NOT your Gmail password. Create one at myaccount.google.com/apppasswords (it is no longer listed on the 2-Step Verification page). Spaces are fine — they're stripped automatically."
        />

        <div class="grid gap-4 sm:grid-cols-3">
          <div>
            <Label>IMAP host</Label>
            <Input v-model="imapHost" placeholder="imap.gmail.com" />
          </div>
          <div>
            <Label>Port</Label>
            <Input v-model="imapPort" type="number" />
          </div>
          <div>
            <Label>Folder</Label>
            <Input v-model="imapFolder" placeholder="INBOX" />
          </div>
        </div>
      </div>
    </CardContent>
  </Card>
</template>
