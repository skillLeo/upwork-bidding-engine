<script setup>
import { onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { apiClient, apiErrorMessage } from "@/lib/api-client";
import { useAuthStore } from "@/stores/auth";
import AuthLayout from "@/components/auth/AuthLayout.vue";
import AuthDeadEnd from "@/components/auth/AuthDeadEnd.vue";
import AuthField from "@/components/auth/AuthField.vue";
import AuthButton from "@/components/auth/AuthButton.vue";

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const token = String(route.query.token ?? "");
const loading = ref(true);
const invalid = ref(false);
const info = ref(null);
const name = ref("");
const password = ref("");
const errors = ref({});
const formError = ref("");
const submitting = ref(false);

onMounted(async () => {
  if (!token) {
    invalid.value = true;
    loading.value = false;
    return;
  }
  try {
    const res = await apiClient.get("/invitations/show", { params: { token } });
    info.value = res.data.data;
  } catch {
    invalid.value = true;
  } finally {
    loading.value = false;
  }
});

async function accept() {
  errors.value = {};
  formError.value = "";
  if (info.value.needs_account) {
    if (!name.value) {
      errors.value = { name: "Enter your name." };
      return;
    }
    if (password.value.length < 8) {
      errors.value = { password: "Use at least 8 characters." };
      return;
    }
  }
  submitting.value = true;
  try {
    const payload = { token };
    if (info.value.needs_account) {
      payload.name = name.value;
      payload.password = password.value;
      payload.password_confirmation = password.value;
    }
    const res = await apiClient.post("/invitations/accept", payload);
    auth.setAuth(res.data.data.token, null, true);
    const me = await apiClient.get("/me");
    auth.setUser(me.data.data);
    router.replace("/leads");
  } catch (error) {
    formError.value = apiErrorMessage(error, "Could not accept the invitation.");
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <AuthDeadEnd
    v-if="invalid"
    title="This invitation is no longer valid"
    body="It may have expired or already been used. Ask whoever invited you to send a fresh one."
    action-label="Back to sign in"
    action-to="/login"
  />

  <AuthLayout v-else>
    <template v-if="loading">
      <p style="font-size: 15px; color: var(--paper-ink-2)">Checking your invitation…</p>
    </template>

    <template v-else>
      <h1 class="auth-h1">
        Join {{ info.workspace }}
      </h1>
      <p class="auth-sub">
        <template v-if="info.invited_by">{{ info.invited_by }} invited you</template>
        <template v-else>You've been invited</template>
        as {{ info.role }} · {{ info.email }}
      </p>

      <form class="mt-8 flex flex-col gap-4" novalidate @submit.prevent="accept">
        <template v-if="info.needs_account">
          <AuthField id="name" v-model="name" label="Your name" autocomplete="name" :error="errors.name" />
          <AuthField
            id="password"
            v-model="password"
            label="Create a password"
            type="password"
            autocomplete="new-password"
            :error="errors.password"
          />
        </template>
        <p v-else style="font-size: 15px; color: var(--paper-ink-2)">
          You already have an account with this email — joining adds this workspace to it.
        </p>

        <div class="auth-error" :class="{ open: !!formError }" aria-live="polite">
          <span>{{ formError }}</span>
        </div>

        <AuthButton type="submit" :loading="submitting">
          {{ info.needs_account ? "Create account and join" : "Join workspace" }}
        </AuthButton>
      </form>
    </template>
  </AuthLayout>
</template>
