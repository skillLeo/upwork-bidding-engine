"use client";

import * as React from "react";
import { CheckCircle2, XCircle, Zap } from "lucide-react";
import { Button } from "@/components/ui/Button";
import { testConnection } from "@/lib/hooks/useSettings";
import type { TestConnectionResult } from "@/lib/types";

export function TestConnectionButton({ service }: { service: string }) {
  const [loading, setLoading] = React.useState(false);
  const [result, setResult] = React.useState<TestConnectionResult | null>(null);

  async function handleTest() {
    setLoading(true);
    setResult(null);
    try {
      const res = await testConnection(service);
      setResult(res);
    } catch {
      setResult({ success: false, message: "The test request itself failed." });
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="flex flex-col items-end gap-1.5">
      <Button type="button" variant="secondary" size="sm" onClick={handleTest} loading={loading}>
        <Zap className="h-3.5 w-3.5" /> Test connection
      </Button>
      {result && (
        <div
          className={
            result.success
              ? "flex items-start gap-1 text-xs font-medium text-success"
              : "flex items-start gap-1 text-xs font-medium text-danger"
          }
        >
          {result.success ? (
            <CheckCircle2 className="mt-0.5 h-3.5 w-3.5 shrink-0" />
          ) : (
            <XCircle className="mt-0.5 h-3.5 w-3.5 shrink-0" />
          )}
          <span className="max-w-[220px] text-right">{result.message}</span>
        </div>
      )}
    </div>
  );
}
