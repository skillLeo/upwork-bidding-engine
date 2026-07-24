<script setup>
import { onMounted, ref, watch } from "vue";
import { toast } from "vue-sonner";
import QRCode from "qrcode";
import { KeyRound, Mail, ShieldCheck } from "@lucide/vue";
import Card from "@/components/ui/Card.vue";
import CardHeader from "@/components/ui/CardHeader.vue";
import CardTitle from "@/components/ui/CardTitle.vue";
import CardContent from "@/components/ui/CardContent.vue";
import CardDescription from "@/components/ui/CardDescription.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
import FieldError from "@/components/ui/FieldError.vue";
import { apiClient, apiErrorMessage } from "@/lib/api-client";
import { useAuthStore } from "@/stores/auth";

const auth = useAuthStore();

// ------------------------------------------------------------ authenticator app (TOTP)

const totpEnabled = ref(!!auth.user?.totp_enabled);
watch(() => auth.user?.totp_enabled, (v) => (totpEnabled.value = !!v));

const enrolling = ref(false);
const enrollData = ref(null); // { secret, otpauth_url }
const qrDataUrl = ref("");
const confirmCode = ref("");
const confirming = ref(false);
const confirmError = ref("");
const recoveryCodes = ref(null); // shown once, right after confirm/regenerate

const disablePassword = ref("");
const disabling = ref(false);
const showDisableForm = ref(false);

const regenPassword = ref("");
const regenerating = ref(false);
const showRegenForm = ref(false);

async function startEnroll() {
  enrolling.value = true;
  confirmError.value = "";
  try {
    const res = await apiClient.post("/profile/totp/enroll");
    enrollData.value = res.data.data;
    qrDataUrl.value = await QRCode.toDataURL(enrollData.value.otpauth_url, { width: 220, margin: 1 });
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not start enrolment."));
    enrollData.value = null;
  } finally {
    enrolling.value = false;
  }
}

async function confirmEnroll() {
  confirmError.value = "";
  if (!confirmCode.value) {
    confirmError.value = "Enter the 6-digit code from your app.";
    return;
  }
  confirming.value = true;
  try {
    const res = await apiClient.post("/profile/totp/confirm", { code: confirmCode.value });
    recoveryCodes.value = res.data.data.recovery_codes;
    totpEnabled.value = true;
    auth.setUser({ ...auth.user, totp_enabled: true });
    enrollData.value = null;
    confirmCode.value = "";
    toast.success("Authenticator app enabled.");
  } catch (error) {
    confirmError.value = apiErrorMessage(error, "That code is incorrect.");
  } finally {
    confirming.value = false;
  }
}

async function disableTotp() {
  if (!disablePassword.value) return;
  disabling.value = true;
  try {
    await apiClient.post("/profile/totp/disable", { password: disablePassword.value });
    totpEnabled.value = false;
    auth.setUser({ ...auth.user, totp_enabled: false });
    disablePassword.value = "";
    showDisableForm.value = false;
    recoveryCodes.value = null;
    toast.success("Authenticator app disabled.");
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not disable — check your password."));
  } finally {
    disabling.value = false;
  }
}

async function regenerateCodes() {
  if (!regenPassword.value) return;
  regenerating.value = true;
  try {
    const res = await apiClient.post("/profile/totp/recovery-codes/regenerate", { password: regenPassword.value });
    recoveryCodes.value = res.data.data.recovery_codes;
    regenPassword.value = "";
    showRegenForm.value = false;
    toast.success("New recovery codes generated. The old ones no longer work.");
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not regenerate — check your password."));
  } finally {
    regenerating.value = false;
  }
}

// ------------------------------------------------------------------- email OTP

const emailOtpEnabled = ref(!!auth.user?.two_factor_enabled);
const emailOtpSaving = ref(false);
watch(() => auth.user?.two_factor_enabled, (v) => (emailOtpEnabled.value = !!v));

async function toggleEmailOtp(event) {
  const next = event.target.checked;
  emailOtpSaving.value = true;
  try {
    const res = await apiClient.put("/profile/two-factor", { enabled: next });
    auth.setUser(res.data.data);
    toast.success(next ? "Email code login enabled." : "Email code login disabled.");
  } catch (error) {
    emailOtpEnabled.value = !next;
    toast.error(apiErrorMessage(error, "Could not update email code setting."));
  } finally {
    emailOtpSaving.value = false;
  }
}
</script>

<template>
  <Card>
    <CardHeader>
      <CardTitle class="flex items-center gap-2">
        <ShieldCheck class="h-4 w-4 text-primary" /> Two-factor authentication
      </CardTitle>
    </CardHeader>
    <CardContent class="space-y-6">
      <!-- Authenticator app -->
      <div>
        <div class="mb-2 flex items-center gap-2">
          <KeyRound class="h-4 w-4 text-text-tertiary" />
          <p class="text-sm font-semibold text-text-primary">Authenticator app</p>
          <span class="rounded-pill bg-primary-tint px-2 py-0.5 text-[10px] font-semibold tracking-wide text-primary uppercase">Recommended</span>
        </div>
        <CardDescription class="mb-3">
          A code from an app like Google Authenticator or 1Password — works even if email is down,
          and doesn't depend on this system's own mail account.
        </CardDescription>

        <template v-if="totpEnabled">
          <p class="mb-2 text-sm text-success">Enabled on this account.</p>
          <div class="flex flex-wrap gap-2">
            <Button variant="secondary" size="sm" @click="showRegenForm = !showRegenForm">Regenerate recovery codes</Button>
            <Button variant="danger" size="sm" @click="showDisableForm = !showDisableForm">Disable</Button>
          </div>

          <div v-if="showRegenForm" class="mt-3 max-w-sm rounded-md border border-border bg-surface-subtle p-3">
            <Label>Current password</Label>
            <Input type="password" autocomplete="current-password" v-model="regenPassword" @keyup.enter="regenerateCodes" />
            <Button class="mt-2" size="sm" :loading="regenerating" @click="regenerateCodes">Generate new codes</Button>
          </div>

          <div v-if="showDisableForm" class="mt-3 max-w-sm rounded-md border border-danger/30 bg-danger-bg p-3">
            <Label>Current password</Label>
            <Input type="password" autocomplete="current-password" v-model="disablePassword" @keyup.enter="disableTotp" />
            <Button class="mt-2" variant="danger" size="sm" :loading="disabling" @click="disableTotp">Confirm disable</Button>
          </div>

          <div v-if="recoveryCodes" class="mt-3 max-w-sm rounded-md border border-warning-border bg-warning-bg p-3">
            <p class="mb-2 text-xs font-semibold text-text-primary">
              Save these 8 recovery codes now — shown once, each works one time.
            </p>
            <div class="grid grid-cols-2 gap-1.5 font-mono text-xs text-text-primary">
              <span v-for="c in recoveryCodes" :key="c" class="rounded bg-white px-2 py-1">{{ c }}</span>
            </div>
            <Button class="mt-2" variant="ghost" size="sm" @click="recoveryCodes = null">Done — I saved them</Button>
          </div>
        </template>

        <template v-else-if="enrollData">
          <div class="flex flex-col gap-4 sm:flex-row">
            <img :src="qrDataUrl" alt="QR code for authenticator app" class="h-[220px] w-[220px] shrink-0 rounded-md border border-border" />
            <div class="min-w-0 flex-1">
              <p class="mb-1 text-xs text-text-tertiary">Scan the QR code, or enter this key manually:</p>
              <p class="mb-3 truncate rounded bg-surface-subtle px-2 py-1.5 font-mono text-xs text-text-primary">{{ enrollData.secret }}</p>
              <Label for="totp-confirm">Enter the 6-digit code your app shows</Label>
              <Input
                id="totp-confirm"
                inputmode="numeric"
                autocomplete="one-time-code"
                placeholder="123456"
                v-model="confirmCode"
                class="max-w-[160px] text-center tracking-[0.3em]"
                @keyup.enter="confirmEnroll"
              />
              <FieldError :text="confirmError" />
              <div class="mt-2 flex gap-2">
                <Button size="sm" :loading="confirming" @click="confirmEnroll">Activate</Button>
                <Button variant="ghost" size="sm" @click="enrollData = null">Cancel</Button>
              </div>
            </div>
          </div>
        </template>

        <template v-else>
          <Button variant="secondary" size="sm" :loading="enrolling" @click="startEnroll">Set up authenticator app</Button>
        </template>
      </div>

      <div class="h-px bg-border" />

      <!-- Email OTP -->
      <div>
        <div class="mb-2 flex items-center gap-2">
          <Mail class="h-4 w-4 text-text-tertiary" />
          <p class="text-sm font-semibold text-text-primary">Email code</p>
        </div>
        <CardDescription class="mb-2">
          A 6-digit code emailed to you at sign-in. Works without an app, but depends on email
          delivery.
        </CardDescription>
        <label class="flex items-center gap-2 text-sm text-text-secondary">
          <input
            type="checkbox"
            :checked="emailOtpEnabled"
            @change="toggleEmailOtp"
            :disabled="emailOtpSaving"
            class="h-4 w-4 rounded border-border-strong text-primary focus:ring-primary/20"
          />
          Require an emailed code when signing in
        </label>
      </div>
    </CardContent>
  </Card>
</template>
