<script setup>
import { onMounted, ref } from "vue";
import { toast } from "vue-sonner";
import PlatformShell from "@/components/platform/PlatformShell.vue";
import { apiClient, apiErrorMessage } from "@/lib/api-client";

const loading = ref(true);
const saving = ref(false);
const form = ref(null);
const clientSecretInput = ref("");
const clientSecretIsSet = ref(false);

async function load() {
  loading.value = true;
  try {
    const res = await apiClient.get("/platform/settings");
    const data = res.data.data;
    clientSecretIsSet.value = !!data.google_oauth_client_secret?.is_set;
    form.value = {
      signup_mode: data.signup_mode ?? "invite_code",
      scoring_system_prompt: data.scoring_system_prompt ?? "",
      proposal_skill: data.proposal_skill ?? "",
      stage_2_scoring_addendum: data.stage_2_scoring_addendum ?? "",
      platform_default_scoring_model: data.platform_default_scoring_model ?? "",
      platform_default_proposal_model: data.platform_default_proposal_model ?? "",
      platform_default_review_model: data.platform_default_review_model ?? "",
      google_oauth_client_id: data.google_oauth_client_id ?? "",
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
    if (clientSecretInput.value) payload.google_oauth_client_secret = clientSecretInput.value;
    await apiClient.put("/platform/settings", payload);
    clientSecretInput.value = "";
    toast.success("Platform settings saved.");
    await load();
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not save platform settings."));
  } finally {
    saving.value = false;
  }
}

onMounted(load);
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
        <h2 class="mb-2 text-sm font-semibold text-white">Google OAuth</h2>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="mb-1 block text-xs text-white/50">Client ID</label>
            <input v-model="form.google_oauth_client_id" class="h-9 w-full rounded-md border border-white/10 bg-white/5 px-2 text-sm text-white focus:border-amber-500 focus:outline-none" />
          </div>
          <div>
            <label class="mb-1 block text-xs text-white/50">Client secret {{ clientSecretIsSet ? "(set — leave blank to keep)" : "" }}</label>
            <input v-model="clientSecretInput" type="password" class="h-9 w-full rounded-md border border-white/10 bg-white/5 px-2 text-sm text-white focus:border-amber-500 focus:outline-none" />
          </div>
        </div>
      </section>

      <button type="button" :disabled="saving" @click="save" class="rounded-md bg-amber-500 px-4 py-2 text-sm font-semibold text-black disabled:opacity-50">
        {{ saving ? "Saving…" : "Save platform settings" }}
      </button>
    </div>
  </PlatformShell>
</template>
