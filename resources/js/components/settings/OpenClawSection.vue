<script setup>
import { ref, watch } from "vue";
import { toast } from "vue-sonner";
import Card from "@/components/ui/Card.vue";
import CardHeader from "@/components/ui/CardHeader.vue";
import CardTitle from "@/components/ui/CardTitle.vue";
import CardContent from "@/components/ui/CardContent.vue";
import CardDescription from "@/components/ui/CardDescription.vue";
import Button from "@/components/ui/Button.vue";
import SecretField from "@/components/ui/SecretField.vue";
import TestConnectionButton from "@/components/settings/TestConnectionButton.vue";
import { saveSettings } from "@/composables/useSettings";
import { apiErrorMessage } from "@/lib/api-client";

import { apiClient } from "@/lib/api-client";

const props = defineProps({ settings: { type: Object, required: true } });
const emit = defineEmits(["saved"]);

const url = ref("");
const token = ref("");
const aiEngineEnabled = ref(props.settings.ai_engine_enabled);

const agentToken = ref(null);
const agentTokenBusy = ref(false);

async function revealAgentToken() {
  agentTokenBusy.value = true;
  try {
    const res = await apiClient.get("/settings/agent-token");
    agentToken.value = res.data.data.token || "(not generated yet — click Regenerate)";
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not reveal the token."));
  } finally {
    agentTokenBusy.value = false;
  }
}

async function regenerateAgentToken() {
  if (
    props.settings.agent_api_token?.is_set &&
    !window.confirm("Regenerate? The current token stops working immediately.")
  ) {
    return;
  }
  agentTokenBusy.value = true;
  try {
    const res = await apiClient.post("/settings/agent-token/regenerate");
    agentToken.value = res.data.data.token;
    toast.success("New Agent API token generated.");
    emit("saved");
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not regenerate the token."));
  } finally {
    agentTokenBusy.value = false;
  }
}

async function copyAgentToken() {
  if (!agentToken.value) return;
  await navigator.clipboard.writeText(agentToken.value);
  toast.success("Token copied.");
}
const saving = ref(false);

// Re-sync whenever the settings prop changes (after a save/refetch), same
// pattern as the other non-secret fields in this app (e.g. RulesSection).
watch(
  () => props.settings.ai_engine_enabled,
  (value) => {
    aiEngineEnabled.value = value;
  },
);

async function handleSave() {
  saving.value = true;
  try {
    await saveSettings({
      openclaw_url: url.value,
      openclaw_token: token.value,
      ai_engine_enabled: aiEngineEnabled.value,
    });
    url.value = "";
    token.value = "";
    toast.success("AI Engine settings saved.");
    emit("saved");
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not save AI Engine settings."));
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <Card>
    <CardHeader>
      <CardTitle>AI Engine (via OpenClaw)</CardTitle>
      <TestConnectionButton service="openclaw" />
    </CardHeader>
    <CardContent class="space-y-4">
      <CardDescription>
        Scoring, proposal drafting, and reply drafting all run through OpenClaw, which is
        authenticated to Claude on its own — no Anthropic API key is stored here.
      </CardDescription>
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
      <div class="rounded-md border border-border bg-neutral-bg/50 p-4">
        <p class="text-sm font-semibold text-text-primary">Agent API token</p>
        <p class="mt-1 text-xs text-text-tertiary">
          OpenClaw's only key into this app — it can read leads/clients and mark statuses,
          never touch settings, API keys, or delete anything.
          {{ settings.agent_api_token?.is_set ? "" : "Not generated yet." }}
        </p>
        <p
          v-if="agentToken"
          class="mt-2 rounded-md bg-white px-3 py-2 font-mono text-xs break-all text-text-primary"
        >
          {{ agentToken }}
        </p>
        <div class="mt-2 flex gap-2">
          <Button variant="secondary" size="sm" :loading="agentTokenBusy" @click="revealAgentToken">
            Reveal
          </Button>
          <Button
            variant="secondary"
            size="sm"
            :loading="agentTokenBusy"
            @click="regenerateAgentToken"
          >
            Regenerate
          </Button>
          <Button v-if="agentToken" variant="ghost" size="sm" @click="copyAgentToken">Copy</Button>
        </div>
      </div>

      <label class="flex items-center gap-2 text-sm text-text-secondary">
        <input
          type="checkbox"
          v-model="aiEngineEnabled"
          class="h-4 w-4 rounded border-border-strong text-primary focus:ring-primary/20"
        />
        AI engine online
      </label>
      <p class="text-xs text-text-tertiary">
        Turn this off to pause scoring and reply drafting without affecting lead intake from
        Vollna — new leads keep arriving, they just wait for AI processing until this is back on.
      </p>
      <div class="flex justify-end">
        <Button @click="handleSave" :loading="saving">Save AI Engine settings</Button>
      </div>
    </CardContent>
  </Card>
</template>
