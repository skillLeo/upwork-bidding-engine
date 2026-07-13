<script setup>
import { ref, computed } from "vue";
import { useRoute, useRouter } from "vue-router";
import { toast } from "vue-sonner";
import { ArrowLeft, Copy, MessageCircleWarning, Sparkles } from "@lucide/vue";
import { useClient, draftReply } from "@/composables/useClient";
import PageContainer from "@/components/layout/PageContainer.vue";
import Card from "@/components/ui/Card.vue";
import Button from "@/components/ui/Button.vue";
import StagePill from "@/components/ui/StagePill.vue";
import Textarea from "@/components/ui/Textarea.vue";
import Label from "@/components/ui/Label.vue";
import Skeleton from "@/components/ui/Skeleton.vue";
import SkeletonText from "@/components/ui/SkeletonText.vue";
import EmptyState from "@/components/ui/EmptyState.vue";
import Avatar from "@/components/ui/Avatar.vue";
import MessageBubble from "@/components/leads/MessageBubble.vue";
import { apiErrorMessage } from "@/lib/api-client";

const route = useRoute();
const router = useRouter();
const { client, isLoading, refetch } = useClient(() => route.params.id);

const draft = ref("");
const drafting = ref(false);
const lastDrafted = ref(null);

const messages = computed(() => client.value?.messages ?? []);

async function handleDraft() {
  if (!client.value || !draft.value.trim()) return;
  drafting.value = true;
  try {
    const message = await draftReply(client.value.id, draft.value.trim());
    lastDrafted.value = message;
    draft.value = "";
    refetch();
    if (message.drafted_reply) {
      toast.success("Reply drafted.");
    } else {
      toast.warning("Message saved, but drafting failed — try again or write one manually.");
    }
  } catch (error) {
    toast.error(apiErrorMessage(error, "Could not draft a reply."));
  } finally {
    drafting.value = false;
  }
}

async function handleCopy(text) {
  await navigator.clipboard.writeText(text);
  toast.success("Copied.");
}
</script>

<template>
  <PageContainer v-if="isLoading" class="max-w-[760px]">
    <Skeleton class="mb-4 h-5 w-32" />
    <Card class="p-6">
      <SkeletonText :lines="5" />
    </Card>
  </PageContainer>

  <PageContainer v-else-if="!client" class="max-w-[760px]">
    <EmptyState title="Client not found">
      <template #action>
        <Button @click="router.push('/leads')">Back to leads</Button>
      </template>
    </EmptyState>
  </PageContainer>

  <PageContainer v-else class="max-w-[760px]">
    <router-link
      to="/leads"
      class="mb-4 inline-flex items-center gap-1.5 text-sm font-medium text-text-secondary hover:text-primary"
    >
      <ArrowLeft class="h-4 w-4" /> Back to leads
    </router-link>

    <Card class="p-6">
      <div class="flex items-start justify-between gap-3">
        <div class="flex min-w-0 items-center gap-3">
          <Avatar :name="client.name" size="lg" />
          <div class="min-w-0">
            <h1 class="line-clamp-1 font-semibold text-text-primary">{{ client.name }}</h1>
            <router-link
              v-if="client.lead_id"
              :to="`/leads/${client.lead_id}`"
              class="text-xs text-primary hover:underline"
            >
              View original lead
            </router-link>
          </div>
        </div>
        <StagePill :stage="client.stage" />
      </div>

      <div class="mt-4 grid grid-cols-2 gap-3">
        <div class="rounded-md bg-neutral-bg p-3">
          <p class="text-xs font-medium text-text-tertiary">Budget discussed</p>
          <p class="mt-1 text-sm font-medium text-text-primary">
            {{ client.budget_discussed ?? "—" }}
          </p>
        </div>
        <div class="rounded-md bg-neutral-bg p-3">
          <p class="text-xs font-medium text-text-tertiary">Agreed scope</p>
          <p class="mt-1 line-clamp-2 text-sm font-medium text-text-primary">
            {{ client.agreed_scope ?? "—" }}
          </p>
        </div>
      </div>

      <div v-if="client.notes" class="mt-4 rounded-md border border-dashed border-border-strong p-3">
        <p class="text-xs font-semibold tracking-wide text-text-tertiary uppercase">Running notes</p>
        <p class="mt-1 text-sm whitespace-pre-wrap text-text-secondary">{{ client.notes }}</p>
      </div>
    </Card>

    <Card class="mt-4 p-6">
      <p class="mb-4 text-sm font-semibold text-text-primary">Conversation</p>
      <EmptyState
        v-if="messages.length === 0"
        title="No messages yet"
        description="Paste the client's first message below to get started."
      />
      <div v-else class="thin-scroll max-h-[420px] space-y-3 overflow-y-auto pr-1">
        <MessageBubble
          v-for="message in messages"
          :key="message.id"
          :message="message"
          @copy="handleCopy"
        />
      </div>
    </Card>

    <Card class="mt-4 p-6">
      <Label for="reply-box">Paste the client's message</Label>
      <Textarea
        id="reply-box"
        rows="4"
        v-model="draft"
        placeholder="Paste what the client said on Upwork…"
      />
      <div class="mt-3 flex justify-end">
        <Button @click="handleDraft" :loading="drafting" :disabled="!draft.trim()">
          <Sparkles class="h-4 w-4" /> Draft reply
        </Button>
      </div>

      <div
        v-if="lastDrafted?.drafted_reply"
        class="mt-4 rounded-md border border-primary/30 bg-primary-tint p-4"
      >
        <div class="flex items-center justify-between">
          <p class="text-xs font-semibold tracking-wide text-primary uppercase">Suggested reply</p>
          <Button variant="secondary" size="sm" @click="handleCopy(lastDrafted.drafted_reply)">
            <Copy class="h-3.5 w-3.5" /> Copy
          </Button>
        </div>
        <p class="mt-2 text-sm whitespace-pre-wrap text-text-primary">
          {{ lastDrafted.drafted_reply }}
        </p>
        <div
          v-if="lastDrafted.needs_hassam"
          class="mt-3 flex items-center gap-1.5 text-xs font-medium text-warning"
        >
          <MessageCircleWarning class="h-4 w-4" /> Touches price/closing — loop in Hassam before
          sending.
        </div>
      </div>
    </Card>
  </PageContainer>
</template>
