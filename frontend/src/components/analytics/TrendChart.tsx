"use client";

import {
  CartesianGrid,
  Legend,
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import { format } from "date-fns";
import type { AnalyticsTrendPoint } from "@/lib/types";
import { CHART_CHROME, CHART_COLORS } from "@/lib/chart-colors";

export function TrendChart({ data }: { data: AnalyticsTrendPoint[] }) {
  const formatted = data.map((d) => ({
    ...d,
    label: format(new Date(`${d.date}T00:00:00`), "MMM d"),
  }));

  return (
    <ResponsiveContainer width="100%" height={280}>
      <LineChart data={formatted} margin={{ top: 8, right: 12, left: -16, bottom: 0 }}>
        <CartesianGrid stroke={CHART_CHROME.grid} vertical={false} />
        <XAxis
          dataKey="label"
          tick={{ fontSize: 11, fill: CHART_CHROME.axis }}
          axisLine={{ stroke: CHART_CHROME.grid }}
          tickLine={false}
          interval="preserveStartEnd"
        />
        <YAxis
          allowDecimals={false}
          tick={{ fontSize: 11, fill: CHART_CHROME.axis }}
          axisLine={false}
          tickLine={false}
          width={32}
        />
        <Tooltip
          contentStyle={{
            borderRadius: 8,
            border: `1px solid ${CHART_CHROME.border}`,
            boxShadow: "0 8px 24px rgba(0,0,0,0.12)",
            fontSize: 12,
          }}
          labelStyle={{ color: CHART_CHROME.textPrimary, fontWeight: 600, marginBottom: 4 }}
        />
        <Legend wrapperStyle={{ fontSize: 12, color: CHART_CHROME.textSecondary }} iconType="line" iconSize={14} />
        <Line
          type="monotone"
          dataKey="received"
          name="Received"
          stroke={CHART_COLORS.received}
          strokeWidth={2}
          dot={false}
          activeDot={{ r: 4 }}
        />
        <Line
          type="monotone"
          dataKey="sent"
          name="Sent"
          stroke={CHART_COLORS.sent}
          strokeWidth={2}
          dot={false}
          activeDot={{ r: 4 }}
        />
        <Line
          type="monotone"
          dataKey="replied"
          name="Replied"
          stroke={CHART_COLORS.replied}
          strokeWidth={2}
          dot={false}
          activeDot={{ r: 4 }}
        />
        <Line
          type="monotone"
          dataKey="won"
          name="Won"
          stroke={CHART_COLORS.won}
          strokeWidth={2}
          dot={false}
          activeDot={{ r: 4 }}
        />
      </LineChart>
    </ResponsiveContainer>
  );
}
