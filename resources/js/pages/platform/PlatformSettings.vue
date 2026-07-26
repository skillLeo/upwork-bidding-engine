<script setup>
import { onMounted, ref } from "vue";
import { toast } from "vue-sonner";
import PlatformShell from "@/components/platform/PlatformShell.vue";
import { apiClient, apiErrorMessage } from "@/lib/api-client";

const loading = ref(true);
const saving = ref(false);
const testingMail = ref(false);
const form = ref(null);

// Write-only fields. The server never echoes a stored secret back, so each
// one shows "is set" and stays blank; submitting blank means "keep what's
// there", which is why they live outside `form`.
const secretInputs = ref({
  google_oauth_client_secret: "",
  anthropic_api_key: "",
  openai_api_key: "",
  mail_username: "",
  mail_password: "",
});
const secretIsSet = ref({});

// Verified against the current model catalogs — never guessed.
const MODELS = [
  { id: "claude-haiku-4-5", label: "Claude Haiku 4.5 — $1/$5 per MTok" },
  { id: "claude-sonnet-5", label: "Claude Sonnet 5 — $3/$15 per MTok" },
  { id: "claude-sonnet-4-6", label: "Claude Sonnet 4.6 — $3/$15 per MTok" },
  { id: "claude-opus-4-8", label: "Claude Opus 4.8 — $5/$25 per MTok" },
  { id: "gpt-4o-mini", label: "GPT-4o mini — $0.15/$0.60 per MTok" },
  { id: "gpt-4o", label: "GPT-4o — $2.50/$10 per MTok" },
];

async function load() {
  loading.value = true;
  try {
    const res = await apiClient.get("/platform/settings");
    const data = res.data.data;

    secretIsSet.value = Object.fromEntries(
      Object.keys(secretInputs.value).map((k) => [k, !!data[k]?.is_set]),
    );

    form.value = {
      signup_mode: data.signup_mode ?? "invite_code",
      scoring_system_prompt: data.scoring_system_prompt ?? "",
      proposal_skill: data.proposal_skill ?? "",
      stage_2_scoring_addendum: data.stage_2_scoring_addendum ?? "",
      platform_default_scoring_model: data.platform_default_scoring_model ?? "",
      platform_default_proposal_model: data.platform_default_proposal_model ?? "",
      platform_default_review_model: data.platform_default_review_model ?? "",
      google_oauth_client_id: data.google_oauth_client_id ?? "",
      ai_provider: data.ai_provider ?? "anthropic",
      scoring_model: data.scoring_model ?? "claude-haiku-4-5",
      proposal_model: data.proposal_model ?? "claude-sonnet-5",
      review_model: data.review_model ?? "claude-sonnet-5",
      mail_host: data.mail_host ?? "",
      mail_port: data.mail_port ?? 587,
      mail_encryption: data.mail_encryption ?? "tls",
      mail_from_address: data.mail_from_address ?? "",
      mail_from_name: data.mail_from_name ?? "",
    };
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not load platform settings."));
  } finally {
    loading.value = false;
  }
}

async function save() {
  saving.value = true;
  try {
    const payload = { ...form.value };

    for (const [key, value] of Object.entries(secretInputs.value)) {
      if (value) payload[key] = value;
    }

    await apiClient.put("/platform/settings", payload);

    for (const key of Object.keys(secretInputs.value)) secretInputs.value[key] = "";
    toast.success("Platform settings saved.");
    await load();
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not save platform settings."));
  } finally {
    saving.value = false;
  }
}

// ------------------------------------------------- platform ownership
const ownership = ref(null);
const transferring = ref(false);
const transfer = ref({ user_id: null, password: "", totp_code: "" });

async function loadOwnership() {
  try {
    const res = await apiClient.get("/platform/ownership");
    ownership.value = res.data.data;
  } catch {
    // Support and billing staff can't read this; their console simply
    // doesn't show the section.
    ownership.value = null;
  }
}

async function transferOwnership() {
  const to = ownership.value?.current_owner?.email;
  if (!confirm(`Transfer platform ownership away from ${to}? You will stop being platform staff immediately and cannot undo this yourself.`)) return;

  transferring.value = true;
  try {
    const res = await apiClient.post("/platform/ownership/transfer", {
      user_id: transfer.value.user_id,
      password: transfer.value.password,
      totp_code: transfer.value.totp_code || undefined,
    });
    toast.success(res.data.data.message);
    transfer.value = { user_id: null, password: "", totp_code: "" };
    await loadOwnership();
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not transfer platform ownership."));
  } finally {
    transferring.value = false;
  }
}

async function testMail() {
  testingMail.value = true;
  try {
    const res = await apiClient.post("/platform/settings/test-mail");
    const result = res.data.data;
    result.success ? toast.success(result.message) : toast.error(result.message);
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not send the test email."));
  } finally {
    testingMail.value = false;
  }
}

onMounted(() => {
  load();
  loadOwnership();
});
</script>

<template>
  <PlatformShell title="Platform settings">
    <div v-if="loading" class="text-white/40">Loading…</div>
    <div v-else class="max-w-2xl space-y-6">
      <section>
        <h2 class="mb-2 text-sm font-semibold text-white">Signup</h2>
        <label class="mb-1 block text-xs text-white/50">Signup mode</label>
        <select v-model="form.signup_mode" class="h-9 w-56 rounded-md border border-white/10 bg-white/5 px-2 text-sm text-white focus:border-amber-500 focus:outline-none">
          <option value="open">Open</option>
          <option value="invite_code">Invite code</option>
          <option value="closed">Closed</option>
        </select>
      </section>

      <section>
        <h2 class="mb-2 text-sm font-semibold text-white">Default prompts</h2>
        <label class="mb-1 block text-xs text-white/50">Scoring system prompt</label>
        <textarea v-model="form.scoring_system_prompt" rows="4" class="mb-3 w-full rounded-md border border-white/10 bg-white/5 px-2 py-1.5 text-sm text-white focus:border-amber-500 focus:outline-none" />
        <label class="mb-1 block text-xs text-white/50">Proposal skill</label>
        <textarea v-model="form.proposal_skill" rows="4" class="mb-3 w-full rounded-md border border-white/10 bg-white/5 px-2 py-1.5 text-sm text-white focus:border-amber-500 focus:outline-none" />
        <label class="mb-1 block text-xs text-white/50">Stage-2 scoring addendum</label>
        <textarea v-model="form.stage_2_scoring_addendum" rows="3" class="w-full rounded-md border border-white/10 bg-white/5 px-2 py-1.5 text-sm text-white focus:border-amber-500 focus:outline-none" />
      </section>

      <section>
        <h2 class="mb-2 text-sm font-semibold text-white">Global AI model defaults</h2>
        <p class="mb-2 text-xs text-white/40">Guidance for what new workspaces are seeded with — does not override a workspace's own choice.</p>
        <div class="grid grid-cols-3 gap-3">
          <div>
            <label class="mb-1 block text-xs text-white/50">Scoring</label>
            <input v-model="form.platform_default_scoring_model" class="h-9 w-full rounded-md border border-white/10 bg-white/5 px-2 text-sm text-white focus:border-amber-500 focus:outline-none" />
          </div>
          <div>
            <label class="mb-1 block text-xs text-white/50">Proposal</label>
            <input v-model="form.platform_default_proposal_model" class="h-9 w-full rounded-md border border-white/10 bg-white/5 px-2 text-sm text-white focus:border-amber-500 focus:outline-none" />
          </div>
          <div>
            <label class="mb-1 block text-xs text-white/50">Review</label>
            <input v-model="form.platform_default_review_model" class="h-9 w-full rounded-md border border-white/10 bg-white/5 px-2 text-sm text-white focus:border-amber-500 focus:outline-none" />
          </div>
        </div>
      </section>

      <section>
        <h2 class="mb-2 text-sm font-semibold text-white">AI credentials &amp; models</h2>
        <p class="mb-3 text-xs text-white/40">
          The pooled keys every workspace's calls are billed to. No workspace can see or set
          these — their spend is limited by their own monthly token cap instead.
        </p>

        <div class="mb-3 grid grid-cols-2 gap-3">
          <div>
            <label class="mb-1 block text-xs text-white/50">Provider</label>
            <select v-model="form.ai_provider" class="h-9 w-full rounded-md border border-white/10 bg-white/5 px-2 text-sm text-white focus:border-amber-500 focus:outline-none">
              <option value="anthropic">Anthropic (Claude)</option>
              <option value="openai">OpenAI</option>
            </select>
            <p class="mt-1 text-xs text-white/40">Three consecutive failures fail over to the other provider for 15 minutes.</p>
          </div>
        </div>

        <div class="mb-3 grid grid-cols-2 gap-3">
          <div>
            <label class="mb-1 block text-xs text-white/50">
              Anthropic API key {{ secretIsSet.anthropic_api_key ? "(set — leave blank to keep)" : "" }}
            </label>
            <input v-model="secretInputs.anthropic_api_key" type="password" autocomplete="off" class="h-9 w-full rounded-md border border-white/10 bg-white/5 px-2 text-sm text-white focus:border-amber-500 focus:outline-none" />
          </div>
          <div>
            <label class="mb-1 block text-xs text-white/50">
              OpenAI API key {{ secretIsSet.openai_api_key ? "(set — leave blank to keep)" : "" }}
            </label>
            <input v-model="secretInputs.openai_api_key" type="password" autocomplete="off" class="h-9 w-full rounded-md border border-white/10 bg-white/5 px-2 text-sm text-white focus:border-amber-500 focus:outline-none" />
          </div>
        </div>

        <div class="grid grid-cols-3 gap-3">
          <div>
            <label class="mb-1 block text-xs text-white/50">Scoring model</label>
            <select v-model="form.scoring_model" class="h-9 w-full rounded-md border border-white/10 bg-white/5 px-2 text-sm text-white focus:border-amber-500 focus:outline-none">
              <option v-for="m in MODELS" :key="m.id" :value="m.id">{{ m.label }}</option>
            </select>
          </div>
          <div>
            <label class="mb-1 block text-xs text-white/50">Proposal model</label>
            <select v-model="form.proposal_model" class="h-9 w-full rounded-md border border-white/10 bg-white/5 px-2 text-sm text-white focus:border-amber-500 focus:outline-none">
              <option v-for="m in MODELS" :key="m.id" :value="m.id">{{ m.label }}</option>
            </select>
          </div>
          <div>
            <label class="mb-1 block text-xs text-white/50">Review model</label>
            <select v-model="form.review_model" class="h-9 w-full rounded-md border border-white/10 bg-white/5 px-2 text-sm text-white focus:border-amber-500 focus:outline-none">
              <option v-for="m in MODELS" :key="m.id" :value="m.id">{{ m.label }}</option>
            </select>
          </div>
        </div>
      </section>

      <section>
        <h2 class="mb-2 text-sm font-semibold text-white">Mail (SMTP)</h2>
        <p class="mb-3 text-xs text-white/40">
          One mail configuration for the whole deployment — invitations, password resets and
          sign-in codes all ride it. Send a test after changing anything: the only symptom of a
          broken mail config is silence.
        </p>

        <div class="mb-3 grid grid-cols-3 gap-3">
          <div class="col-span-2">
            <label class="mb-1 block text-xs text-white/50">Host</label>
            <input v-model="form.mail_host" placeholder="smtp.hostinger.com" class="h-9 w-full rounded-md border border-white/10 bg-white/5 px-2 text-sm text-white placeholder:text-white/25 focus:border-amber-500 focus:outline-none" />
          </div>
          <div>
            <label class="mb-1 block text-xs text-white/50">Port</label>
            <input v-model.number="form.mail_port" type="number" class="h-9 w-full rounded-md border border-white/10 bg-white/5 px-2 text-sm text-white focus:border-amber-500 focus:outline-none" />
          </div>
        </div>

        <div class="mb-3 grid grid-cols-3 gap-3">
          <div>
            <label class="mb-1 block text-xs text-white/50">
              Username {{ secretIsSet.mail_username ? "(set)" : "" }}
            </label>
            <input v-model="secretInputs.mail_username" autocomplete="off" class="h-9 w-full rounded-md border border-white/10 bg-white/5 px-2 text-sm text-white focus:border-amber-500 focus:outline-none" />
          </div>
          <div>
            <label class="mb-1 block text-xs text-white/50">
              Password {{ secretIsSet.mail_password ? "(set)" : "" }}
            </label>
            <input v-model="secretInputs.mail_password" type="password" autocomplete="off" class="h-9 w-full rounded-md border border-white/10 bg-white/5 px-2 text-sm text-white focus:border-amber-500 focus:outline-none" />
          </div>
          <div>
            <label class="mb-1 block text-xs text-white/50">Encryption</label>
            <select v-model="form.mail_encryption" class="h-9 w-full rounded-md border border-white/10 bg-white/5 px-2 text-sm text-white focus:border-amber-500 focus:outline-none">
              <option value="tls">TLS</option>
              <option value="ssl">SSL</option>
              <option value="">None</option>
            </select>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="mb-1 block text-xs text-white/50">From address</label>
            <input v-model="form.mail_from_address" type="email" class="h-9 w-full rounded-md border border-white/10 bg-white/5 px-2 text-sm text-white focus:border-amber-500 focus:outline-none" />
          </div>
          <div>
            <label class="mb-1 block text-xs text-white/50">From name</label>
            <input v-model="form.mail_from_name" class="h-9 w-full rounded-md border border-white/10 bg-white/5 px-2 text-sm text-white focus:border-amber-500 focus:outline-none" />
          </div>
        </div>

        <button
          type="button"
          :disabled="testingMail"
          @click="testMail"
          class="mt-3 h-9 rounded-md border border-white/10 px-3 text-sm text-white/80 hover:bg-white/5 disabled:opacity-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/30"
        >
          {{ testingMail ? "Sending…" : "Send test email to me" }}
        </button>
      </section>

      <section>
        <h2 class="mb-2 text-sm font-semibold text-white">Google OAuth</h2>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="mb-1 block text-xs text-white/50">Client ID</label>
            <input v-model="form.google_oauth_client_id" class="h-9 w-full rounded-md border border-white/10 bg-white/5 px-2 text-sm text-white focus:border-amber-500 focus:outline-none" />
          </div>
          <div>
            <label class="mb-1 block text-xs text-white/50">
              Client secret {{ secretIsSet.google_oauth_client_secret ? "(set — leave blank to keep)" : "" }}
            </label>
            <input v-model="secretInputs.google_oauth_client_secret" type="password" autocomplete="off" class="h-9 w-full rounded-md border border-white/10 bg-white/5 px-2 text-sm text-white focus:border-amber-500 focus:outline-none" />
          </div>
        </div>
      </section>

      <button type="button" :disabled="saving" @click="save" class="rounded-md bg-amber-500 px-4 py-2 text-sm font-semibold text-black disabled:opacity-50">
        {{ saving ? "Saving…" : "Save platform settings" }}
      </button>

      <!-- Deliberately last, deliberately outside the Save button above:
           this is the one action here that cannot be undone by the person
           taking it. -->
      <section v-if="ownership?.is_current_owner" class="rounded-lg border border-danger/40 bg-danger/5 p-4">
        <h2 class="mb-1 text-sm font-semibold text-white">Transfer platform ownership</h2>
        <p class="mb-4 text-xs text-white/50">
          Hands every workspace, the platform prompts, and the pooled AI credentials to another
          account. You stop being platform staff the moment it completes, and only the new owner
          can give it back. Currently held by
          <span class="font-mono text-white/80">{{ ownership.current_owner?.email }}</span>.
        </p>

        <div class="grid gap-3 sm:grid-cols-3">
          <div>
            <label class="mb-1 block text-xs text-white/50">New owner — user ID</label>
            <input v-model.number="transfer.user_id" type="number" class="h-9 w-full rounded-md border border-white/10 bg-white/5 px-2 text-sm text-white focus:border-danger focus:outline-none" />
          </div>
          <div>
            <label class="mb-1 block text-xs text-white/50">Your password</label>
            <input v-model="transfer.password" type="password" autocomplete="off" class="h-9 w-full rounded-md border border-white/10 bg-white/5 px-2 text-sm text-white focus:border-danger focus:outline-none" />
          </div>
          <div v-if="ownership.requires_totp">
            <label class="mb-1 block text-xs text-white/50">Authenticator code</label>
            <input v-model="transfer.totp_code" inputmode="numeric" autocomplete="one-time-code" class="h-9 w-full rounded-md border border-white/10 bg-white/5 px-2 font-mono text-sm text-white focus:border-danger focus:outline-none" />
          </div>
        </div>

        <button
          type="button"
          :disabled="transferring || !transfer.user_id || !transfer.password"
          @click="transferOwnership"
          class="mt-4 h-9 rounded-md bg-danger px-3 text-sm font-semibold text-white disabled:opacity-40 focus:outline-none focus-visible:ring-2 focus-visible:ring-danger/50"
        >
          {{ transferring ? "Transferring…" : "Transfer ownership" }}
        </button>
      </section>
    </div>
  </PlatformShell>
</template>
