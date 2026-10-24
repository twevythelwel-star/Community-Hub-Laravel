import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import {
  Link2,
  Copy,
  Check,
  QrCode,
  Smartphone,
  Share2,
  PlusCircle,
  FileText,
  ExternalLink,
} from 'lucide-react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { money } from '@/components/dashboard/billing-summary';

export type PaymentLinkItem = {
  id: number;
  token: string;
  title: string;
  description: string | null;
  amount: number | null;
  currency: string;
  category: string;
  active: boolean;
  usesCount: number;
  publicUrl: string;
  posterUrl: string;
};

export function PaymentLinksManager({
  links,
  canManage,
}: {
  links: PaymentLinkItem[];
  canManage: boolean;
  /** Accepted so the call site type-checks; each link carries its own currency. */
  currency?: string;
}) {
  const [copiedToken, setCopiedToken] = useState<string | null>(null);
  const [isCreateOpen, setCreateOpen] = useState(false);
  const [title, setTitle] = useState('');
  const [description, setDescription] = useState('');
  const [amount, setAmount] = useState('');
  const [currency, setCurrency] = useState('JMD');
  const [category, setCategory] = useState('Assessment');
  const [isSubmitting, setIsSubmitting] = useState(false);

  const handleCopy = (url: string, token: string) => {
    navigator.clipboard.writeText(url);
    setCopiedToken(token);
    setTimeout(() => setCopiedToken(null), 2000);
  };

  const handleShareWhatsApp = (link: PaymentLinkItem) => {
    const text = encodeURIComponent(
      `Hello! Please find the payment link for "${link.title}" here: ${link.publicUrl}`
    );
    window.open(`https://wa.me/?text=${text}`, '_blank');
  };

  const handleCreateLink = (e: React.FormEvent) => {
    e.preventDefault();
    setIsSubmitting(true);

    router.post(
      '/dashboard/billing/payment-links',
      {
        title,
        description,
        amount: amount ? Number(amount) : null,
        currency,
        category,
      },
      {
        onSuccess: () => {
          setIsSubmitting(false);
          setCreateOpen(false);
          setTitle('');
          setDescription('');
          setAmount('');
          setCurrency('JMD');
        },
        onError: () => setIsSubmitting(false),
      }
    );
  };

  return (
    <div className="bg-card border border-border rounded-2xl shadow-lg p-6 sm:p-8 space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-border pb-5">
        <div>
          <h2 className="text-xl font-bold text-foreground flex items-center gap-2">
            <Link2 className="w-5 h-5 text-primary" />
            Universal Multi-Modal Payment Links
          </h2>
          <p className="text-xs text-muted-foreground mt-1">
            Generate unified payment requests distributed via Web Link, WhatsApp, Printable QR Poster, or NFC smart plaque.
          </p>
        </div>

        {canManage && (
          <Button
            size="sm"
            className="bg-primary text-primary-foreground font-semibold text-xs"
            onClick={() => setCreateOpen(true)}
          >
            <PlusCircle className="w-4 h-4 mr-1.5" /> Create Payment Link
          </Button>
        )}
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        {links.map((link) => (
          <div
            key={link.id}
            className="p-5 rounded-xl border border-border bg-muted/20 hover:bg-muted/40 transition-colors flex flex-col justify-between space-y-4"
          >
            <div>
              <div className="flex items-center justify-between gap-2">
                <Badge variant="outline" className="text-[10px] font-semibold">
                  {link.category}
                </Badge>
                <span className="text-[11px] text-muted-foreground font-medium">
                  {link.usesCount} payment{link.usesCount === 1 ? '' : 's'} recorded
                </span>
              </div>

              <h3 className="font-bold text-base text-foreground mt-2">{link.title}</h3>
              {link.description && (
                <p className="text-xs text-muted-foreground mt-1 line-clamp-2">
                  {link.description}
                </p>
              )}

              <div className="mt-3 flex items-baseline gap-2">
                <span className="text-lg font-black text-foreground">
                  {link.amount ? money(link.amount, link.currency) : 'Custom Amount (Payer Chooses)'}
                </span>
                <Badge variant="outline" className="text-[10px] font-mono font-bold uppercase text-indigo-600 dark:text-indigo-400 border-indigo-200 dark:border-indigo-900">
                  {link.currency}
                </Badge>
              </div>
            </div>

            <div className="pt-3 border-t border-border/60 flex flex-wrap items-center gap-2 text-xs">
              <Button
                variant="outline"
                size="sm"
                className="text-xs gap-1.5"
                onClick={() => handleCopy(link.publicUrl, link.token)}
              >
                {copiedToken === link.token ? (
                  <>
                    <Check className="w-3.5 h-3.5 text-emerald-600" />
                    <span>Copied!</span>
                  </>
                ) : (
                  <>
                    <Copy className="w-3.5 h-3.5" />
                    <span>Copy Link</span>
                  </>
                )}
              </Button>

              <Button
                variant="outline"
                size="sm"
                className="text-xs text-emerald-600 hover:text-emerald-700 hover:bg-emerald-50 dark:hover:bg-emerald-950/30 gap-1.5"
                onClick={() => handleShareWhatsApp(link)}
              >
                <Share2 className="w-3.5 h-3.5" />
                <span>WhatsApp</span>
              </Button>

              <a
                href={link.posterUrl}
                target="_blank"
                rel="noreferrer"
                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md border border-border bg-card hover:bg-muted text-xs font-semibold text-primary transition-colors"
              >
                <FileText className="w-3.5 h-3.5" />
                <span>Print QR Poster (PDF)</span>
              </a>

              <a
                href={link.publicUrl}
                target="_blank"
                rel="noreferrer"
                className="p-1.5 text-muted-foreground hover:text-foreground ml-auto"
                title="Preview Checkout"
              >
                <ExternalLink className="w-4 h-4" />
              </a>
            </div>
          </div>
        ))}
      </div>

      {/* Create Link Modal */}
      <Dialog open={isCreateOpen} onOpenChange={setCreateOpen}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <PlusCircle className="w-5 h-5 text-primary" />
              <span>Create Universal Payment Link</span>
            </DialogTitle>
            <DialogDescription>
              Set up a payment request that generates a web link, printable QR flyer, and WhatsApp dispatch.
            </DialogDescription>
          </DialogHeader>

          <form onSubmit={handleCreateLink} className="space-y-4 py-3">
            <div>
              <label className="text-xs font-bold text-muted-foreground block mb-1">
                Payment Request Title *
              </label>
              <Input
                required
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                placeholder="e.g. $150 — Community Security Upgrade"
                className="text-sm font-semibold"
              />
            </div>

            <div>
              <label className="text-xs font-bold text-muted-foreground block mb-1">
                Description / Purpose Statement
              </label>
              <Input
                value={description}
                onChange={(e) => setDescription(e.target.value)}
                placeholder="Details explaining what this payment funds..."
                className="text-xs"
              />
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
              <div>
                <label className="text-xs font-bold text-muted-foreground block mb-1">
                  Currency *
                </label>
                <select
                  value={currency}
                  onChange={(e) => setCurrency(e.target.value)}
                  className="w-full h-9 px-3 rounded-md border border-border bg-background text-xs font-medium focus:ring-2 focus:ring-primary focus:outline-none"
                >
                  <option value="JMD">🇯🇲 JMD (J$)</option>
                  <option value="USD">🇺🇸 USD ($)</option>
                  <option value="CAD">🇨🇦 CAD (CA$)</option>
                  <option value="GBP">🇬🇧 GBP (£)</option>
                  <option value="EUR">🇪🇺 EUR (€)</option>
                </select>
              </div>

              <div>
                <label className="text-xs font-bold text-muted-foreground block mb-1">
                  Fixed Amount
                </label>
                <Input
                  type="number"
                  value={amount}
                  onChange={(e) => setAmount(e.target.value)}
                  placeholder="e.g. 150"
                  className="text-sm font-semibold"
                />
              </div>

              <div>
                <label className="text-xs font-bold text-muted-foreground block mb-1">
                  Category
                </label>
                <select
                  value={category}
                  onChange={(e) => setCategory(e.target.value)}
                  className="w-full h-9 px-3 rounded-md border border-border bg-background text-xs font-medium focus:ring-2 focus:ring-primary focus:outline-none"
                >
                  <option value="Assessment">Assessment</option>
                  <option value="Maintenance">Dues</option>
                  <option value="Security">Security</option>
                  <option value="Amenity">Amenity</option>
                  <option value="Fundraising">Fundraising</option>
                </select>
              </div>
            </div>

            <Button
              type="submit"
              className="w-full font-bold py-3 mt-4"
              disabled={isSubmitting}
            >
              {isSubmitting ? 'Creating Link...' : 'Generate Universal Payment Link'}
            </Button>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
