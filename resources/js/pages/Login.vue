<script setup>
import { ref } from "vue";
import { useRouter } from "vue-router";
import { toast } from "vue-sonner";
import { apiClient, apiErrorMessage } from "@/lib/api-client";
import { useAuthStore } from "@/stores/auth";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
import FieldError from "@/components/ui/FieldError.vue";

const router = useRouter();
const auth = useAuthStore();

const email = ref("");
const password = ref("");
const errors = ref({});
const submitting = ref(false);

// The server decides whether dev quick login is available (config/skillleo.php).
// No credentials live here — clicking asks the server to issue the token.
const devQuickLogin =
  document.querySelector('meta[name="dev-quick-login"]')?.content === "1";

function validate() {
  const next = {};
  if (!email.value || !/^\S+@\S+\.\S+$/.test(email.value)) {
    next.email = "Enter a valid email address.";
  }
  if (!password.value) {
    next.password = "Password is required.";
  }
  errors.value = next;
  return Object.keys(next).length === 0;
}

async function quickLogin(role) {
  submitting.value = true;
  try {
    const res = await apiClient.post("/auth/dev-login", { role });
    auth.setAuth(res.data.data.token, res.data.data.user);
    toast.success(`Signed in as ${role}.`);
    router.replace({ name: "leads" });
  } catch (error) {
    toast.error(apiErrorMessage(error, `Could not sign in as ${role}.`));
  } finally {
    submitting.value = false;
  }
}

async function onSubmit() {
  if (!validate()) return;
  submitting.value = true;
  try {
    const res = await apiClient.post("/auth/login", {
      email: email.value,
      password: password.value,
    });
    auth.setAuth(res.data.data.token, res.data.data.user);
    toast.success("Welcome back.");
    router.replace({ name: "leads" });
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not log in."));
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <div class="flex min-h-screen flex-1 flex-col items-center justify-center bg-bg px-4 py-12">
    <div class="mb-8 flex items-center gap-2 text-2xl font-bold text-primary">
      <span class="flex h-10 w-10 items-center justify-center rounded-md bg-primary text-base font-bold text-white">
        SL
      </span>
      SkillLeo
    </div>

    <div class="w-full max-w-sm rounded-card border border-border bg-surface p-8 shadow-card">
      <h1 class="text-xl font-semibold text-text-primary">Sign in</h1>
      <p class="mt-1 text-sm text-text-secondary">Bidding Engine dashboard</p>

      <form @submit.prevent="onSubmit" class="mt-6 space-y-4" novalidate>
        <div>
          <Label for="email">Email</Label>
          <Input
            id="email"
            type="email"
            autocomplete="email"
            placeholder="you@company.com"
            v-model="email"
          />
          <FieldError :text="errors.email" />
        </div>
        <div>
          <Label for="password">Password</Label>
          <Input
            id="password"
            type="password"
            autocomplete="current-password"
            placeholder="••••••••"
            v-model="password"
          />
          <FieldError :text="errors.password" />
        </div>
        <Button type="submit" class="w-full" size="lg" :loading="submitting">Sign in</Button>
      </form>

      <div v-if="devQuickLogin" class="mt-6">
        <div class="flex items-center gap-3">
          <span class="h-px flex-1 bg-border"></span>
          <span class="text-xs font-medium text-text-tertiary">Dev quick login</span>
          <span class="h-px flex-1 bg-border"></span>
        </div>
        <div class="mt-3 grid grid-cols-2 gap-2">
          <Button
            type="button"
            variant="secondary"
            :disabled="submitting"
            @click="quickLogin('admin')"
          >
            Admin
          </Button>
          <Button
            type="button"
            variant="secondary"
            :disabled="submitting"
            @click="quickLogin('bidder')"
          >
            Bidder
          </Button>
        </div>
      </div>
    </div>

    <div
      v-if="devQuickLogin"
      class="mt-6 max-w-sm rounded-card border border-warning-border bg-warning-bg px-4 py-3 text-center text-xs text-text-secondary"
    >
      Dev quick login is <span class="font-semibold">on</span> — anyone who can open this page can
      sign in without a password. Set <span class="font-mono">DEV_QUICK_LOGIN=false</span> before
      going live.
    </div>
  </div>
</template>
