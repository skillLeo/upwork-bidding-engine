"use client";

import * as React from "react";
import { useParams, useRouter } from "next/navigation";
import Link from "next/link";
import { toast } from "sonner";
import { ArrowLeft, Copy, MessageCircleWarning, Sparkles } from "lucide-react";
import { useClient, draftReply } from "@/lib/hooks/useClient";
import { PageContainer } from "@/components/layout/Rails";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { StagePill } from "@/components/ui/Badge";
import { Textarea, Label } from "@/components/ui/Input";
import { Skeleton, SkeletonText } from "@/components/ui/Skeleton";
import { EmptyState } from "@/components/ui/EmptyState";
import { Avatar } from "@/components/ui/Avatar";
import { cn, formatDateTime } from "@/lib/utils";
import { apiErrorMessage } from "@/lib/api-client";
import type { Message } from "@/lib/types";

export default function ClientMemoryPage() {
  const params = useParams<{ id: string }>();
  const router = useRouter();
  const { client, isLoading, mutate } = useClient(params.id);
  const [draft, setDraft] = React.useState("");
  const [drafting, setDrafting] = React.useState(false);
  const [lastDrafted, setLastDrafted] = React.useState<Message | null>(null);

  async function handleDraft() {
    if (!client || !draft.trim()) return;
    setDrafting(true);
    try {
      const message = await draftReply(client.id, draft.trim());
      setLastDrafted(message);
      setDraft("");
      mutate();
      if (message.drafted_reply) {
        toast.success("Reply drafted.");
      } else {
        toast.warning("Message saved, but drafting failed — try again or write one manually.");
      }
    } catch (error) {
      toast.error(apiErrorMessage(error, "Could not draft a reply."));
    } finally {
      setDrafting(false);
    }
  }

  async function handleCopy(text: string) {
    await navigator.clipboard.writeText(text);
    toast.success("Copied.");
  }

  if (isLoading) {
    return (
      <PageContainer className="max-w-[760px]">
        <Skeleton className="mb-4 h-5 w-32" />
        <Card className="p-6">
          <SkeletonText lines={5} />
        </Card>
      </PageContainer>
    );
  }

  if (!client) {
    return (
      <PageContainer className="max-w-[760px]">
        <EmptyState
          title="Client not found"
          action={<Button onClick={() => router.push("/leads")}>Back to leads</Button>}
        />
      </PageContainer>
    );
  }

  const messages = client.messages ?? [];

  return (
    <PageContainer className="max-w-[760px]">
      <Link
        href="/leads"
        className="mb-4 inline-flex items-center gap-1.5 text-sm font-medium text-text-secondary hover:text-primary"
      >
        <ArrowLeft className="h-4 w-4" /> Back to leads
      </Link>

      <Card className="p-6">
        <div className="flex items-start justify-between gap-3">
          <div className="flex min-w-0 items-center gap-3">
            <Avatar name={client.name} size="lg" />
            <div className="min-w-0">
              <h1 className="line-clamp-1 font-semibold text-text-primary">{client.name}</h1>
              {client.lead_id && (
                <Link
                  href={`/leads/${client.lead_id}`}
                  className="text-xs text-primary hover:underline"
                >
                  View original lead
                </Link>
              )}
            </div>
          </div>
          <StagePill stage={client.stage} />
        </div>

        <div className="mt-4 grid grid-cols-2 gap-3">
          <div className="rounded-md bg-neutral-bg p-3">
            <p className="text-xs font-medium text-text-tertiary">Budget discussed</p>
            <p className="mt-1 text-sm font-medium text-text-primary">
              {client.budget_discussed ?? "—"}
            </p>
          </div>
          <div className="rounded-md bg-neutral-bg p-3">
            <p className="text-xs font-medium text-text-tertiary">Agreed scope</p>
            <p className="mt-1 line-clamp-2 text-sm font-medium text-text-primary">
              {client.agreed_scope ?? "—"}
            </p>
          </div>
        </div>

        {client.notes && (
          <div className="mt-4 rounded-md border border-dashed border-border-strong p-3">
            <p className="text-xs font-semibold tracking-wide text-text-tertiary uppercase">
              Running notes
            </p>
            <p className="mt-1 text-sm whitespace-pre-wrap text-text-secondary">{client.notes}</p>
          </div>
        )}
      </Card>

      <Card className="mt-4 p-6">
        <p className="mb-4 text-sm font-semibold text-text-primary">Conversation</p>
        {messages.length === 0 ? (
          <EmptyState
            title="No messages yet"
            description="Paste the client's first message below to get started."
          />
        ) : (
          <div className="thin-scroll max-h-[420px] space-y-3 overflow-y-auto pr-1">
            {messages.map((message) => (
              <MessageBubble key={message.id} message={message} onCopy={handleCopy} />
            ))}
          </div>
        )}
      </Card>

      <Card className="mt-4 p-6">
        <Label htmlFor="reply-box">Paste the client&apos;s message</Label>
        <Textarea
          id="reply-box"
          rows={4}
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          placeholder="Paste what the client said on Upwork…"
        />
        <div className="mt-3 flex justify-end">
          <Button onClick={handleDraft} loading={drafting} disabled={!draft.trim()}>
            <Sparkles className="h-4 w-4" /> Draft reply
          </Button>
        </div>

        {lastDrafted?.drafted_reply && (
          <div className="mt-4 rounded-md border border-primary/30 bg-primary-tint p-4">
            <div className="flex items-center justify-between">
              <p className="text-xs font-semibold tracking-wide text-primary uppercase">
                Suggested reply
              </p>
              <Button
                variant="secondary"
                size="sm"
                onClick={() => handleCopy(lastDrafted.drafted_reply as string)}
              >
                <Copy className="h-3.5 w-3.5" /> Copy
              </Button>
            </div>
            <p className="mt-2 text-sm whitespace-pre-wrap text-text-primary">
              {lastDrafted.drafted_reply}
            </p>
            {lastDrafted.needs_hassam && (
              <div className="mt-3 flex items-center gap-1.5 text-xs font-medium text-warning">
                <MessageCircleWarning className="h-4 w-4" /> Touches price/closing — loop in
                Hassam before sending.
              </div>
            )}
          </div>
        )}
      </Card>
    </PageContainer>
  );
}

function MessageBubble({
  message,
  onCopy,
}: {
  message: Message;
  onCopy: (text: string) => void;
}) {
  const isOut = message.direction === "out";
  return (
    <div className={cn("flex", isOut ? "justify-end" : "justify-start")}>
      <div
        className={cn(
          "max-w-[85%] rounded-card px-4 py-3 text-sm",
          isOut ? "bg-primary text-white" : "bg-neutral-bg text-text-primary",
        )}
      >
        <p className="whitespace-pre-wrap">{message.text}</p>
        <div
          className={cn(
            "mt-1.5 flex items-center gap-2 text-[11px]",
            isOut ? "text-white/70" : "text-text-tertiary",
          )}
        >
          {formatDateTime(message.sent_at ?? message.created_at)}
          {message.needs_hassam && (
            <span className="inline-flex items-center gap-1 rounded-pill bg-warning-bg px-1.5 py-0.5 font-semibold text-warning">
              <MessageCircleWarning className="h-3 w-3" /> Needs Hassam
            </span>
          )}
        </div>
        {message.drafted_reply && !isOut && (
          <button
            onClick={() => onCopy(message.drafted_reply as string)}
            className="mt-2 flex items-center gap-1 text-xs font-medium text-primary hover:underline"
          >
            <Copy className="h-3 w-3" /> Copy drafted reply
          </button>
        )}
      </div>
    </div>
  );
}
