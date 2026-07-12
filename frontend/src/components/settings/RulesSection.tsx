"use client";

import * as React from "react";
import { toast } from "sonner";
import { Card, CardHeader, CardTitle, CardContent } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Input, Label, FieldHint } from "@/components/ui/Input";
import { TagInput } from "@/components/ui/TagInput";
import { saveSettings } from "@/lib/hooks/useSettings";
import { apiErrorMessage } from "@/lib/api-client";
import type { SettingsResponse } from "@/lib/types";

type Rules = SettingsResponse["rules"];

export function RulesSection({
  settings,
  onSaved,
}: {
  settings: Rules;
  onSaved: () => void;
}) {
  const [form, setForm] = React.useState<Rules>(settings);
  const [saving, setSaving] = React.useState(false);

  // Re-sync local form state when `settings` changes (after a save/refetch)
  // without an Effect — setState during render is React's documented pattern
  // for this, and SWR gives each fetch a new object reference to compare against.
  const [syncedSettings, setSyncedSettings] = React.useState(settings);
  if (settings !== syncedSettings) {
    setSyncedSettings(settings);
    setForm(settings);
  }

  function setField<K extends keyof Rules>(key: K, value: Rules[K]) {
    setForm((f) => ({ ...f, [key]: value }));
  }

  async function handleSave() {
    setSaving(true);
    try {
      await saveSettings({ ...form });
      toast.success("Rules saved.");
      onSaved();
    } catch (error) {
      toast.error(apiErrorMessage(error, "Could not save rules."));
    } finally {
      setSaving(false);
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Bidding rules</CardTitle>
      </CardHeader>
      <CardContent className="space-y-5">
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
          <NumberField
            label="Min budget ($)"
            value={form.min_budget}
            onChange={(v) => setField("min_budget", v)}
          />
          <NumberField
            label="Max proposals"
            value={form.max_proposals}
            onChange={(v) => setField("max_proposals", v)}
          />
          <NumberField
            label="Score cutoff"
            value={form.score_cutoff}
            onChange={(v) => setField("score_cutoff", v)}
            hint="1–10"
          />
          <NumberField
            label="Hourly floor ($/hr)"
            value={form.hourly_floor}
            onChange={(v) => setField("hourly_floor", v)}
          />
          <NumberField
            label="Zero-history budget floor ($)"
            value={form.zero_history_budget_floor}
            onChange={(v) => setField("zero_history_budget_floor", v)}
          />
          <NumberField
            label="Follow up after (days)"
            value={form.followup_days}
            onChange={(v) => setField("followup_days", v)}
          />
        </div>

        <div>
          <Label>Stack keywords</Label>
          <TagInput
            value={form.stack_keywords}
            onChange={(v) => setField("stack_keywords", v)}
            placeholder="Add a keyword and press Enter…"
            testId="stack-keywords-input"
          />
          <FieldHint>Used for scoring context and the Analytics &quot;best job types&quot; chart.</FieldHint>
        </div>

        <div>
          <Label>Red-flag phrases</Label>
          <TagInput
            value={form.red_flag_words}
            onChange={(v) => setField("red_flag_words", v)}
            placeholder="Add a phrase and press Enter…"
            testId="red-flag-words-input"
          />
          <FieldHint>Any brief containing one of these is archived before any AI call runs.</FieldHint>
        </div>

        <div className="flex justify-end">
          <Button onClick={handleSave} loading={saving}>
            Save rules
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}

function NumberField({
  label,
  value,
  onChange,
  hint,
}: {
  label: string;
  value: number;
  onChange: (v: number) => void;
  hint?: string;
}) {
  return (
    <div>
      <Label>{label}</Label>
      <Input
        type="number"
        value={value}
        onChange={(e) => onChange(Number(e.target.value))}
      />
      {hint && <FieldHint>{hint}</FieldHint>}
    </div>
  );
}
