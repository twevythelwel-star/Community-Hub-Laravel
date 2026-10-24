
import { useState, useEffect } from "react";
import { motion, AnimatePresence } from "framer-motion";
import { Image } from "@/components/ui/image";

interface WelcomeAnimationProps {
  username: string;
  communityName?: string;
  onComplete?: () => void;
}

export function WelcomeAnimation({ username, communityName, onComplete }: WelcomeAnimationProps) {
  const [isDone, setIsDone] = useState(false);

  useEffect(() => {
    const timer = setTimeout(() => {
      setIsDone(true);
      onComplete?.();
    }, 2200);
    return () => clearTimeout(timer);
  }, [onComplete]);

  if (isDone) return null;

  return (
    <AnimatePresence>
      {!isDone && (
        <motion.div
          key="splash"
          initial={{ opacity: 1 }}
          exit={{ opacity: 0 }}
          transition={{ duration: 0.4, ease: "easeOut" }}
          className="fixed inset-0 z-[100] flex items-center justify-center overflow-hidden bg-black"
        >
          {/* ── Cinematic Background: slow dolly-forward zoom ── */}
          <motion.div
            initial={{ scale: 1.0 }}
            animate={{ scale: 1.25 }}
            transition={{ duration: 1.8, ease: "linear" }}
            className="absolute inset-0 z-0"
          >
            <Image
              src="/luxury-gate.png"
              alt="Luxury Community Entrance"
              fill
              className="object-cover"
              priority
              unoptimized
            />
          </motion.div>

          {/* ── Dark vignette overlay for depth ── */}
          <div className="absolute inset-0 z-[5] bg-gradient-to-t from-black/60 via-transparent to-black/30 pointer-events-none" />

          {/* ── Golden sun-flare bloom through the gate ── */}
          <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: [0, 0.6, 0.2] }}
            transition={{ duration: 1.2, times: [0, 0.35, 1], ease: "easeInOut" }}
            className="absolute z-10 w-[55vw] h-[55vw] rounded-full bg-amber-300/25 blur-[100px] mix-blend-screen pointer-events-none"
          />

          {/* ── Left gate panel slides open ── */}
          <motion.div
            initial={{ x: "0%" }}
            animate={{ x: "-100%" }}
            transition={{ duration: 0.8, delay: 0.15, ease: [0.16, 1, 0.3, 1] }}
            className="absolute left-0 top-0 z-20 w-1/2 h-full bg-[#080808] flex items-center justify-end shadow-[24px_0_48px_rgba(0,0,0,0.6)]"
          >
            {/* Gold seam on the split edge */}
            <div className="w-[1px] h-full bg-gradient-to-b from-transparent via-amber-400/40 to-transparent" />
          </motion.div>

          {/* ── Right gate panel slides open ── */}
          <motion.div
            initial={{ x: "0%" }}
            animate={{ x: "100%" }}
            transition={{ duration: 0.8, delay: 0.15, ease: [0.16, 1, 0.3, 1] }}
            className="absolute right-0 top-0 z-20 w-1/2 h-full bg-[#080808] flex items-center justify-start shadow-[-24px_0_48px_rgba(0,0,0,0.6)]"
          >
            {/* Gold seam on the split edge */}
            <div className="w-[1px] h-full bg-gradient-to-b from-transparent via-amber-400/40 to-transparent" />
          </motion.div>

          {/* ── Welcome text card — fades in after gates open ── */}
          <motion.div
            initial={{ opacity: 0, scale: 0.92, filter: "blur(8px)" }}
            animate={{ opacity: 1, scale: 1, filter: "blur(0px)" }}
            transition={{ duration: 0.45, delay: 0.7, ease: [0.16, 1, 0.3, 1] }}
            className="relative z-30 flex flex-col items-center"
          >
            <div className="bg-black/35 backdrop-blur-xl px-12 py-8 rounded-2xl border border-white/10 shadow-[0_8px_60px_rgba(0,0,0,0.5)] text-center">
              <motion.p
                initial={{ opacity: 0, y: 8 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.35, delay: 0.85 }}
                className="text-amber-200/80 text-[10px] uppercase tracking-[0.35em] font-medium mb-3"
              >
                {communityName ? `Welcome to ${communityName}` : 'Welcome'}
              </motion.p>
              <motion.h2
                initial={{ opacity: 0, y: 10 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.35, delay: 0.95 }}
                className="text-white text-2xl font-light tracking-wide"
              >
                {username}
              </motion.h2>
            </div>
          </motion.div>

        </motion.div>
      )}
    </AnimatePresence>
  );
}
