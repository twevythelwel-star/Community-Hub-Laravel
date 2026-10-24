import { useState, useMemo } from "react";
import { Head, router } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Calendar } from "@/components/ui/calendar";
import { Button } from "@/components/ui/button";
import { EventForm } from "@/components/dashboard/event-form";
import { submit } from "@/lib/submit";
import type { CommunityEvent } from "@/types";
import { useAuth } from "@/context/auth-context";
import { isSameDay, format, isValid, parseISO } from "date-fns";
import { Image } from "@/components/ui/image";
import {
  CalendarDays,
  Clock,
  PlusCircle,
  ShieldCheck,
  UserCheck,
  ShieldAlert,
  ArrowRight,
  Sparkles,
  CheckCircle2,
} from "lucide-react";
import { Badge } from "@/components/ui/badge";

type EntrySlot = {
  id: string;
  name: string;
  timeRange: string;
  status: string;
  description: string;
};

type VisitorSummary = {
  id: number | string;
  name: string;
  type: string;
  status: string;
  expectedAt: string;
  dateRange: string | null;
};

type UserStay = {
  stayType: string;
  leaseStart: string;
  leaseEnd: string;
  expired?: boolean;
} | null;

type Props = {
  events: {
    id: number | string;
    title: string;
    description: string;
    startDate: string;
    endDate?: string | null;
    imageUrl?: string | null;
  }[];
  entrySlots?: EntrySlot[];
  myVisitors?: VisitorSummary[];
  userStay?: UserStay;
  canManage?: boolean;
  canRegisterVisitors?: boolean;
};

export default function CalendarPage({
  events: initialEvents = [],
  entrySlots = [],
  myVisitors = [],
  userStay,
  canManage: serverCanManage,
  canRegisterVisitors = true,
}: Props) {
  const { user } = useAuth();
  const [selectedDate, setSelectedDate] = useState<Date | undefined>(new Date());
  const [isFormOpen, setFormOpen] = useState(false);
  const [selectedEvent, setSelectedEvent] = useState<CommunityEvent | undefined>(undefined);

  const canManage = serverCanManage ?? (user?.role === "System Admin" || user?.role === "Admin");

  // Parse events into Date objects
  const parsedEvents = useMemo(() => {
    return initialEvents.map((e) => ({
      ...e,
      id: String(e.id),
      startDate: parseISO(e.startDate),
      endDate: e.endDate ? parseISO(e.endDate) : undefined,
      imageUrl: e.imageUrl || undefined,
    }));
  }, [initialEvents]);

  const dateEvents = useMemo(() => {
    if (!selectedDate) return [];
    return parsedEvents.filter((event) => isSameDay(event.startDate, selectedDate));
  }, [parsedEvents, selectedDate]);

  const dateVisitors = useMemo(() => {
    if (!selectedDate) return [];
    return myVisitors.filter((v) => isSameDay(parseISO(v.expectedAt), selectedDate));
  }, [myVisitors, selectedDate]);

  const isWithinStay = useMemo(() => {
    if (!userStay || !selectedDate) return true;
    const start = parseISO(userStay.leaseStart);
    start.setHours(0, 0, 0, 0);
    const end = parseISO(userStay.leaseEnd);
    end.setHours(23, 59, 59, 999);
    const sel = new Date(selectedDate);
    sel.setHours(12, 0, 0, 0);
    return sel >= start && sel <= end;
  }, [userStay, selectedDate]);

  const formatEventTime = (event: { startDate: Date; endDate?: Date }) => {
    const { startDate, endDate } = event;
    const isAllDay =
      startDate.getHours() === 0 &&
      startDate.getMinutes() === 0 &&
      (!endDate || (endDate.getHours() === 0 && endDate.getMinutes() === 0));

    if (isAllDay) return "All-day event";

    let timeString = "";
    if (isValid(startDate)) {
      timeString = format(startDate, "h:mm a");
    }
    if (isValid(endDate) && endDate) {
      timeString += ` – ${format(endDate, "h:mm a")}`;
    }
    return timeString;
  };

  const handleRegisterVisitorForDate = () => {
    const formatted = selectedDate ? format(selectedDate, "yyyy-MM-dd") : format(new Date(), "yyyy-MM-dd");
    router.get(`/dashboard/visitors?date=${formatted}`);
  };

  return (
    <DashboardLayout>
      <Head title="Calendar & Entry Slots" />

      <div className="space-y-6">
        {/* Header */}
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div>
            <h1 className="text-3xl font-bold font-headline tracking-tight flex items-center gap-2">
              <CalendarDays className="h-7 w-7 text-primary" />
              Community &amp; Entry Calendar
            </h1>
            <p className="text-sm text-muted-foreground mt-1">
              View available gate entry slots, community events, and scheduled property visits.
            </p>
          </div>

          <div className="flex items-center gap-3">
            {canRegisterVisitors && (
              <Button
                onClick={handleRegisterVisitorForDate}
                disabled={!isWithinStay}
                className="gap-2 shadow-sm"
              >
                <PlusCircle className="h-4 w-4" />
                Register Visitor for {selectedDate ? format(selectedDate, "MMM d") : "Date"}
              </Button>
            )}

            {canManage && (
              <Button
                variant="outline"
                size="sm"
                className="gap-1.5"
                onClick={() => {
                  setSelectedEvent(undefined);
                  setFormOpen(true);
                }}
              >
                <Sparkles className="h-3.5 w-3.5" />
                Add Event
              </Button>
            )}
          </div>
        </div>

        {/* Temporary Homeowner Stay Notice */}
        {userStay && (
          <div
            className={`rounded-xl border p-4 flex items-start gap-3 ${
              isWithinStay
                ? "border-blue-500/20 bg-blue-500/5 text-foreground"
                : "border-amber-500/30 bg-amber-500/10 text-amber-900 dark:text-amber-200"
            }`}
          >
            <ShieldAlert
              className={`h-5 w-5 mt-0.5 shrink-0 ${
                isWithinStay ? "text-blue-600 dark:text-blue-400" : "text-amber-600 dark:text-amber-400"
              }`}
            />
            <div className="text-xs sm:text-sm space-y-1">
              <p className="font-semibold flex items-center gap-2">
                Temporary Homeowner Stay ({userStay.stayType})
                {isWithinStay ? (
                  <Badge variant="outline" className="text-blue-600 border-blue-500/30 text-[10px]">
                    Date Within Stay
                  </Badge>
                ) : (
                  <Badge variant="outline" className="text-amber-600 border-amber-500/40 text-[10px]">
                    Outside Stay Timeframe
                  </Badge>
                )}
              </p>
              <p className="text-muted-foreground">
                Authorized stay: <strong>{format(parseISO(userStay.leaseStart), "MMM d, yyyy")}</strong> to{" "}
                <strong>{format(parseISO(userStay.leaseEnd), "MMM d, yyyy")}</strong>. Visitor registrations are available on days within this window.
              </p>
            </div>
          </div>
        )}

        {/* Main Grid: Calendar Picker + Details */}
        <div className="grid lg:grid-cols-12 gap-6">
          {/* Calendar Picker Card */}
          <Card className="lg:col-span-5 shadow-sm">
            <CardHeader className="pb-3">
              <CardTitle className="text-base font-semibold">Select Date</CardTitle>
              <CardDescription>
                Choose a date to view gate clearance availability and events.
              </CardDescription>
            </CardHeader>
            <CardContent className="flex justify-center pb-6">
              <Calendar
                mode="single"
                selected={selectedDate}
                onSelect={setSelectedDate}
                className="rounded-lg border shadow-sm p-3 pointer-events-auto"
              />
            </CardContent>
          </Card>

          {/* Right Column: Entry Slots & Day Overview */}
          <div className="lg:col-span-7 space-y-6">
            {/* Entry Slots Card */}
            <Card className="shadow-sm">
              <CardHeader className="pb-3 flex flex-row items-center justify-between">
                <div>
                  <CardTitle className="text-base font-semibold flex items-center gap-2">
                    <ShieldCheck className="h-4 w-4 text-emerald-600" />
                    Available Entry Clearance Slots
                  </CardTitle>
                  <CardDescription>
                    {selectedDate ? format(selectedDate, "EEEE, MMMM d, yyyy") : "Select a day"}
                  </CardDescription>
                </div>
                {canRegisterVisitors && isWithinStay && (
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={handleRegisterVisitorForDate}
                    className="text-xs gap-1.5"
                  >
                    <span>Register</span>
                    <ArrowRight className="h-3.5 w-3.5" />
                  </Button>
                )}
              </CardHeader>
              <CardContent className="space-y-3">
                {entrySlots.map((slot) => (
                  <div
                    key={slot.id}
                    className="flex flex-col sm:flex-row sm:items-center sm:justify-between p-3 rounded-lg border bg-card hover:bg-muted/30 transition-colors gap-2"
                  >
                    <div className="space-y-0.5">
                      <div className="flex items-center gap-2">
                        <span className="font-semibold text-sm text-foreground">{slot.name}</span>
                        <Badge
                          variant="secondary"
                          className="text-[10px] bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border-emerald-500/20 flex items-center gap-1 font-medium"
                        >
                          <CheckCircle2 className="h-2.5 w-2.5" />
                          {slot.status}
                        </Badge>
                      </div>
                      <p className="text-xs text-muted-foreground">{slot.description}</p>
                    </div>
                    <div className="flex items-center gap-2 shrink-0">
                      <span className="text-xs font-mono font-medium px-2 py-1 rounded bg-muted text-muted-foreground">
                        {slot.timeRange}
                      </span>
                    </div>
                  </div>
                ))}
              </CardContent>
            </Card>

            {/* Scheduled Visitors for Selected Day */}
            <Card className="shadow-sm">
              <CardHeader className="pb-3">
                <CardTitle className="text-base font-semibold flex items-center gap-2">
                  <UserCheck className="h-4 w-4 text-primary" />
                  Your Scheduled Visitors for this Day
                </CardTitle>
                <CardDescription>
                  Visitor clearances pre-authorized by your profile for {selectedDate ? format(selectedDate, "MMM d") : ""}.
                </CardDescription>
              </CardHeader>
              <CardContent>
                {dateVisitors.length === 0 ? (
                  <p className="text-xs text-muted-foreground py-2">
                    No visitor arrivals currently scheduled for this date.
                  </p>
                ) : (
                  <div className="space-y-2">
                    {dateVisitors.map((visitor) => (
                      <div
                        key={visitor.id}
                        className="flex items-center justify-between p-2.5 rounded-lg border bg-muted/20 text-xs"
                      >
                        <div className="space-y-0.5">
                          <p className="font-semibold text-foreground">{visitor.name}</p>
                          <p className="text-muted-foreground">
                            {visitor.type} access · {format(parseISO(visitor.expectedAt), "h:mm a")}
                          </p>
                        </div>
                        <Badge variant="outline">{visitor.status}</Badge>
                      </div>
                    ))}
                  </div>
                )}
              </CardContent>
            </Card>

            {/* Community Events on this Day */}
            <Card className="shadow-sm">
              <CardHeader className="pb-3">
                <CardTitle className="text-base font-semibold">Community Events</CardTitle>
                <CardDescription>
                  Events hosted in the community on {selectedDate ? format(selectedDate, "MMMM d, yyyy") : ""}.
                </CardDescription>
              </CardHeader>
              <CardContent>
                {dateEvents.length === 0 ? (
                  <p className="text-xs text-muted-foreground py-3 text-center">
                    No community events scheduled for this day.
                  </p>
                ) : (
                  <div className="space-y-3">
                    {dateEvents.map((event) => (
                      <div key={event.id} className="p-3 rounded-lg border bg-card space-y-1.5">
                        {event.imageUrl && (
                          <div className="relative h-28 w-full rounded overflow-hidden mb-2">
                            <Image
                              src={event.imageUrl}
                              alt={event.title}
                              layout="fill"
                              objectFit="cover"
                            />
                          </div>
                        )}
                        <div className="flex items-start justify-between">
                          <h4 className="font-semibold text-sm">{event.title}</h4>
                          <span className="text-xs text-muted-foreground flex items-center gap-1">
                            <Clock className="h-3 w-3" />
                            {formatEventTime(event)}
                          </span>
                        </div>
                        <p className="text-xs text-muted-foreground">{event.description}</p>
                      </div>
                    ))}
                  </div>
                )}
              </CardContent>
            </Card>
          </div>
        </div>
      </div>

      {canManage && (
        <EventForm
          open={isFormOpen}
          onOpenChange={setFormOpen}
          onSave={(data, id) => {
            const payload = {
              title: data.title,
              description: data.description,
              start_date: data.startDate.toISOString(),
              end_date: data.endDate ? data.endDate.toISOString() : null,
              image_url: data.imageUrl ?? null,
            };

            return id
              ? submit('patch', `/dashboard/calendar/${id}`, payload)
              : submit('post', '/dashboard/calendar', payload);
          }}
          event={selectedEvent}
        />
      )}
    </DashboardLayout>
  );
}
