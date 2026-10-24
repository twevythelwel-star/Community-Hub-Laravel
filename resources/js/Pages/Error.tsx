import { Head, Link } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Button } from '@/components/ui/button';
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import { ArrowLeft, Home, Lock, SearchX, ServerCrash, TimerReset } from 'lucide-react';

/**
 * In-app error page.
 *
 * Rendered inside DashboardLayout so the sidebar stays on screen. A refused
 * page used to hand the user Laravel's bare error page — no navigation, no
 * way back except the browser button — which is a dead end in an app whose
 * whole shell is the navigation.
 */

type Props = {
  status: number;
  message?: string | null;
};

const PRESENTATION: Record<
  number,
  { icon: typeof Lock; title: string; description: string }
> = {
  403: {
    icon: Lock,
    title: 'You do not have access to this page',
    description:
      'Your role does not include this area of the estate. If you think it should, contact community administration.',
  },
  404: {
    icon: SearchX,
    title: 'That page does not exist',
    description:
      'The link may be out of date, or the record it pointed to may have been removed.',
  },
  419: {
    icon: TimerReset,
    title: 'Your session expired',
    description: 'Sign in again to pick up where you left off.',
  },
  429: {
    icon: TimerReset,
    title: 'Too many requests',
    description: 'Give it a moment and try again.',
  },
  500: {
    icon: ServerCrash,
    title: 'Something went wrong on our end',
    description:
      'The problem has been logged. Try again, and tell community administration if it keeps happening.',
  },
  503: {
    icon: ServerCrash,
    title: 'The community hub is down for maintenance',
    description: 'It should be back shortly.',
  },
};

export default function ErrorPage({ status, message }: Props) {
  const { icon: Icon, title, description } =
    PRESENTATION[status] ?? PRESENTATION[500];

  return (
    <DashboardLayout>
      <Head title={`${status} — ${title}`} />

      <div className="flex min-h-[60vh] items-center justify-center py-10">
        <Card className="w-full max-w-lg">
          <CardHeader className="items-center text-center">
            <div className="mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-muted">
              <Icon className="h-7 w-7 text-muted-foreground" aria-hidden="true" />
            </div>
            <p className="text-sm font-semibold tracking-widest text-muted-foreground">
              {status}
            </p>
            <CardTitle className="text-2xl">{title}</CardTitle>
            <CardDescription className="max-w-sm">
              {message || description}
            </CardDescription>
          </CardHeader>

          <CardContent className="flex flex-col gap-2 sm:flex-row sm:justify-center">
            <Button variant="outline" onClick={() => window.history.back()}>
              <ArrowLeft className="mr-2 h-4 w-4" aria-hidden="true" />
              Go back
            </Button>

            <Link href="/dashboard">
              <Button className="w-full sm:w-auto">
                <Home className="mr-2 h-4 w-4" aria-hidden="true" />
                Back to dashboard
              </Button>
            </Link>
          </CardContent>
        </Card>
      </div>
    </DashboardLayout>
  );
}
