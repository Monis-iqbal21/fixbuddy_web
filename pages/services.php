<?php
// services.php
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fix Buddy – Services</title>

    <link rel="stylesheet" href="./assets/css/custom.css">
    <link rel="stylesheet" href="./assets/css/leaflet.css">
    <link rel="stylesheet" href="./assets/css/output-scss.css">
    <link rel="stylesheet" href="./assets/css/quil.snow.css">
    <link rel="stylesheet" href="./assets/css/slick.css">
    <link rel="stylesheet" href="./assets/css/style.css">
    <link rel="stylesheet" href="./assets/css/swiper-bundle.min.css">
    <link rel="stylesheet" href="./assets/css/apexcharts.css">
    <link rel="stylesheet" href="./assets/css/output-tailwind.css">
    <script src="https://unpkg.com/phosphor-icons"></script>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@3.3.3/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="m-0 p-0  border-box bg-white text-[#111827]">

    <!-- NAVBAR -->
    <div class="w-full border-box">
        <?php include '../components/navbar2.php'; ?>
    </div>

    <!-- HERO / INTRO -->
    <section class="lg:pt-14 sm:pt-12 px-6 flex justify-center pt-10 lg:pb-16 sm:pb-12 pb-10 bg-white border-b border-[#E5E7EB]">
        <div class="container">
            <!-- small label -->
            <div class="inline-flex items-center gap-2 rounded-full bg-[#FFF7ED] px-3 py-1 text-xs sm:text-[13px] text-[#EA580C] mb-5">
                <span class="w-2 h-2 rounded-full bg-[#EA580C]"></span>
                <span>Fix Buddy Services</span>
            </div>

            <div class="flex flex-col lg:flex-row gap-8 lg:gap-12 items-start">
                <!-- Left column -->
                <div class="lg:w-1/2 w-full">
                    <h1 class="text-3xl sm:text-[32px] lg:text-[36px] leading-tight font-semibold text-[#0F172A] mb-4">
                        Complete Home Service Solutions in<br class="hidden sm:block" /> One Place.
                    </h1>
                    <p class="text-sm sm:text-[15px] text-[#4B5563] max-w-xl">
                        Fix Buddy connects homeowners with trusted technicians for everything from essential repairs
                        to planned upgrades. Explore our core service categories below and see how we can help keep
                        your home running smoothly—anytime.
                    </p>

                    <div class="grid grid-cols-2 w-[100%] justify-center gap-4 mt-6 px-8 md:px-0 md:w-[70%] text-xs sm:text-sm">
                        <div class="rounded-2xl bg-[#F9FAFB] border border-[#E5E7EB] px-4 py-3">
                            <p class="text-[#6B7280] mb-1">Cities Served</p>
                            <p class="font-semibold text-[#111827]">10+ &amp; growing</p>
                        </div>
                        <div class="rounded-2xl bg-[#F9FAFB] border border-[#E5E7EB] px-4 py-3">
                            <p class="text-[#6B7280] mb-1">Service Types</p>
                            <p class="font-semibold text-[#111827]">20+ specialties</p>
                        </div>
                        <div class="rounded-2xl bg-[#F9FAFB] border border-[#E5E7EB] px-4 py-3">
                            <p class="text-[#6B7280] mb-1">Verified Technicians</p>
                            <p class="font-semibold text-[#111827]">Vetted &amp; rated</p>
                        </div>
                        <div class="rounded-2xl bg-[#F9FAFB] border border-[#E5E7EB] px-4 py-3">
                            <p class="text-[#6B7280] mb-1">Support</p>
                            <p class="font-semibold text-[#111827]">7 days a week</p>
                        </div>
                    </div>
                </div>

                <!-- Right highlight card (dark like About page) -->
                <div class="lg:w-1/2">
                    <div class="rounded-3xl bg-[#020617] text-white px-6 sm:px-8 py-6 sm:py-7 lg:py-8 shadow-[0_22px_60px_rgba(15,23,42,0.55)]">
                        <p class="text-xs sm:text-[13px] tracking-[0.18em] uppercase text-[#FDBA74] mb-3">
                            Why our services work
                        </p>
                        <h2 class="text-lg sm:text-xl font-semibold mb-3">
                            One platform for all your fixes, installs, and upgrades.
                        </h2>
                        <p class="text-sm sm:text-[15px] text-[#E5E7EB] mb-5">
                            Instead of calling multiple vendors, Fix Buddy lets you access certified professionals
                            across different trades—electrical, plumbing, AC, flooring, pest control and more.
                            Every job is tracked, documented, and reviewed for quality.
                        </p>

                        <div class="grid grid-cols-2 gap-4 text-xs sm:text-sm">
                            <div class="rounded-2xl bg-[#030712] border border-[#111827] px-4 py-3 flex flex-col gap-1">
                                <span class="text-[#9CA3AF]">Transparent scope</span>
                                <span class="font-semibold text-white">Clear expectations before work begins.</span>
                            </div>
                            <div class="rounded-2xl bg-[#030712] border border-[#111827] px-4 py-3 flex flex-col gap-1">
                                <span class="text-[#9CA3AF]">Quality tracking</span>
                                <span class="font-semibold text-white">Ratings &amp; feedback on every job.</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CORE SERVICES GRID -->
    <section class="w-full lg:py-16 sm:py-12 py-10 bg-[#F9FAFB]">
        <div class="px-6">
            <div class="flex flex-col px-6 sm:flex-row sm:items-end sm:justify-between gap-3 mb-8">
                <div>
                    <h2 class="text-2xl sm:text-[26px] font-semibold text-[#111827] mb-2">
                        Core Home Service Categories
                    </h2>
                    <p class="text-sm sm:text-[15px] text-[#6B7280] max-w-2xl">
                        Each category is managed by trained professionals who follow safety standards and provide
                        reliable, long–term solutions—not just temporary fixes.
                    </p>
                </div>
                
            </div>

            <!-- cards -->
            <div class="grid gap-6 justify-center px-6 md:gap-7 xl:gap-8 sm:grid-cols-2 xl:grid-cols-3">
                <!-- Electrical -->
                <article class="rounded-3xl bg-white border border-[#E5E7EB] shadow-sm p-6 sm:p-7 flex flex-col h-full">
                    <div class="flex items-center gap-3 mb-4">
                        <span class="icon-setting text-3xl sm:text-4xl text-[#F97316]"></span>
                        <div>
                            <h3 class="text-lg font-semibold text-[#111827]">Electrical Services</h3>
                            <p class="text-xs text-[#6B7280]">Safe wiring, lighting &amp; power solutions</p>
                        </div>
                    </div>
                    <p class="text-sm text-[#4B5563]">
                        From minor faults to full rewiring, Fix Buddy electricians handle repairs,
                        installation and upgrades while keeping safety a priority.
                    </p>
                    <ul class="mt-4 space-y-1.5 text-xs sm:text-sm text-[#6B7280] list-disc list-inside">
                        <li>Fault finding &amp; breaker trips</li>
                        <li>Lights, fans, sockets &amp; switches</li>
                        <li>Meter, DB &amp; panel maintenance</li>
                        <li>Safety inspections for old wiring</li>
                    </ul>
                </article>

                <!-- Plumbing -->
                <article class="rounded-3xl bg-white border border-[#E5E7EB] shadow-sm p-6 sm:p-7 flex flex-col h-full">
                    <div class="flex items-center gap-3 mb-4">
                        <span class="icon-repair text-3xl sm:text-4xl text-[#F97316]"></span>
                        <div>
                            <h3 class="text-lg font-semibold text-[#111827]">Plumbing Solutions</h3>
                            <p class="text-xs text-[#6B7280]">Water, drainage &amp; fixtures</p>
                        </div>
                    </div>
                    <p class="text-sm text-[#4B5563]">
                        Leak fixing, line replacement and drain cleaning for bathrooms, kitchens,
                        tanks and outdoor plumbing.
                    </p>
                    <ul class="mt-4 space-y-1.5 text-xs sm:text-sm text-[#6B7280] list-disc list-inside">
                        <li>Leak detection &amp; pipe repairs</li>
                        <li>Drain blockages &amp; slow flow issues</li>
                        <li>Tap, shower, commode &amp; sink fitting</li>
                        <li>Geyser, tank &amp; pump connections</li>
                    </ul>
                </article>

                <!-- AC -->
                <article class="rounded-3xl bg-white border border-[#E5E7EB] shadow-sm p-6 sm:p-7 flex flex-col h-full">
                    <div class="flex items-center gap-3 mb-4">
                        <span class="icon-thermometer text-3xl sm:text-4xl text-[#F97316]"></span>
                        <div>
                            <h3 class="text-lg font-semibold text-[#111827]">A/C &amp; Cooling</h3>
                            <p class="text-xs text-[#6B7280]">Installation, service &amp; repair</p>
                        </div>
                    </div>
                    <p class="text-sm text-[#4B5563]">
                        Keep your home comfortable with seasonal AC service, breakdown repairs
                        and performance tuning.
                    </p>
                    <ul class="mt-4 space-y-1.5 text-xs sm:text-sm text-[#6B7280] list-disc list-inside">
                        <li>Split &amp; window AC installation</li>
                        <li>Gas charging &amp; cooling checks</li>
                        <li>Complete service &amp; filter cleaning</li>
                        <li>Noise, leakage &amp; low–cooling fixes</li>
                    </ul>
                </article>

                <!-- Car mechanic -->
                <article class="rounded-3xl bg-white border border-[#E5E7EB] shadow-sm p-6 sm:p-7 flex flex-col h-full">
                    <div class="flex items-center gap-3 mb-4">
                        <span class="icon-car text-3xl sm:text-4xl text-[#F97316]"></span>
                        <div>
                            <h3 class="text-lg font-semibold text-[#111827]">Car Mechanic</h3>
                            <p class="text-xs text-[#6B7280]">Maintenance &amp; breakdown support</p>
                        </div>
                    </div>
                    <p class="text-sm text-[#4B5563]">
                        Service and repair options for everyday vehicles—scheduled maintenance
                        and urgent mechanical issues.
                    </p>
                    <ul class="mt-4 space-y-1.5 text-xs sm:text-sm text-[#6B7280] list-disc list-inside">
                        <li>Engine diagnostics &amp; tuning</li>
                        <li>Oil, filter &amp; fluid changes</li>
                        <li>Brakes, suspension &amp; steering</li>
                        <li>Battery &amp; electrical troubleshooting</li>
                    </ul>
                </article>

                <!-- Flooring -->
                <article class="rounded-3xl bg-white border border-[#E5E7EB] shadow-sm p-6 sm:p-7 flex flex-col h-full">
                    <div class="flex items-center gap-3 mb-4">
                        <span class="icon-ruler text-3xl sm:text-4xl text-[#F97316]"></span>
                        <div>
                            <h3 class="text-lg font-semibold text-[#111827]">Flooring &amp; Surface Work</h3>
                            <p class="text-xs text-[#6B7280]">Installations &amp; repairs</p>
                        </div>
                    </div>
                    <p class="text-sm text-[#4B5563]">
                        Upgrade floors with professional installation, repair and finishing
                        for homes, offices and retail spaces.
                    </p>
                    <ul class="mt-4 space-y-1.5 text-xs sm:text-sm text-[#6B7280] list-disc list-inside">
                        <li>Wood, laminate &amp; vinyl floors</li>
                        <li>Tile installation &amp; re-grouting</li>
                        <li>Marble / stone polishing</li>
                        <li>Fixing uneven or damaged surfaces</li>
                    </ul>
                </article>

                <!-- Pest control -->
                <article class="rounded-3xl bg-white border border-[#E5E7EB] shadow-sm p-6 sm:p-7 flex flex-col h-full">
                    <div class="flex items-center gap-3 mb-4">
                        <span class="icon-bug text-3xl sm:text-4xl text-[#F97316]"></span>
                        <div>
                            <h3 class="text-lg font-semibold text-[#111827]">Pest Control</h3>
                            <p class="text-xs text-[#6B7280]">Preventive &amp; targeted treatment</p>
                        </div>
                    </div>
                    <p class="text-sm text-[#4B5563]">
                        Keep your property protected from pests with safe and
                        planned treatments for different infestations.
                    </p>
                    <ul class="mt-4 space-y-1.5 text-xs sm:text-sm text-[#6B7280] list-disc list-inside">
                        <li>Termite &amp; wood–borer solutions</li>
                        <li>Cockroach, ant &amp; mosquito control</li>
                        <li>Rodent proofing &amp; trapping</li>
                        <li>Scheduled preventive service plans</li>
                    </ul>
                </article>

                <!-- Appliance repair -->
                <article class="rounded-3xl bg-white border border-[#E5E7EB] shadow-sm p-6 sm:p-7 flex flex-col h-full">
                    <div class="flex items-center gap-3 mb-4">
                        <i class="ph ph-washing-machine text-3xl sm:text-4xl text-[#F97316]"></i>
                        <div>
                            <h3 class="text-lg font-semibold text-[#111827]">Home Appliance Repair</h3>
                            <p class="text-xs text-[#6B7280]">Fridge, washer &amp; more</p>
                        </div>
                    </div>
                    <p class="text-sm text-[#4B5563]">
                        Fix Buddy technicians handle most common home appliances, helping
                        extend their life instead of immediate replacement.
                    </p>
                    <ul class="mt-4 space-y-1.5 text-xs sm:text-sm text-[#6B7280] list-disc list-inside">
                        <li>Refrigerator &amp; deep freezer issues</li>
                        <li>Washing machine &amp; dryer faults</li>
                        <li>Microwave &amp; oven problems</li>
                        <li>Water dispenser &amp; small appliances</li>
                    </ul>
                </article>

                <!-- Handyman -->
                <article class="rounded-3xl bg-white border border-[#E5E7EB] shadow-sm p-6 sm:p-7 flex flex-col h-full">
                    <div class="flex items-center gap-3 mb-4">
                        <i class="ph ph-toolbox text-3xl sm:text-4xl text-[#F97316]"></i>
                        <div>
                            <h3 class="text-lg font-semibold text-[#111827]">General Handyman</h3>
                            <p class="text-xs text-[#6B7280]">All the small but important fixes</p>
                        </div>
                    </div>
                    <p class="text-sm text-[#4B5563]">
                        For jobs that don’t belong to a single category—assembly,
                        mounting, minor repairs and general upkeep.
                    </p>
                    <ul class="mt-4 space-y-1.5 text-xs sm:text-sm text-[#6B7280] list-disc list-inside">
                        <li>Furniture assembly &amp; adjustments</li>
                        <li>TV, shelf &amp; curtain rod mounting</li>
                        <li>Locks, hinges &amp; hardware fixes</li>
                        <li>Miscellaneous minor maintenance</li>
                    </ul>
                </article>
            </div>
        </div>
    </section>

    <!-- PROCESS STRIP -->
    <section class="px-8 lg:py-16 sm:py-12 py-10 bg-white border-t border-[#E5E7EB]">
        <div class="">
            <div class="grid justify-center md:grid-cols-3 gap-8 items-start">
                <div class="md:col-span-1">
                    <p class="text-xs sm:text-[13px] tracking-[0.18em] uppercase text-[#EA580C] mb-2">
                        Service Flow
                    </p>
                    <h2 class="text-xl sm:text-2xl font-semibold text-[#111827] mb-3">
                        How Fix Buddy runs each job from start to finish.
                    </h2>
                    <p class="text-sm sm:text-[15px] text-[#6B7280]">
                        No matter the service type, we follow a consistent workflow so you know what to expect every time.
                    </p>
                </div>
                <div class="md:col-span-2 grid sm:grid-cols-3 gap-6 text-sm">
                    <div class="rounded-2xl bg-[#F9FAFB] border border-[#E5E7EB] p-4 sm:p-5">
                        <p class="text-xs font-semibold text-[#EA580C] mb-2">01. Share the issue</p>
                        <p class="text-xs sm:text-sm text-[#4B5563]">
                            You describe the problem, location and any photos or notes. This helps us assign the right specialist.
                        </p>
                    </div>
                    <div class="rounded-2xl bg-[#F9FAFB] border border-[#E5E7EB] p-4 sm:p-5">
                        <p class="text-xs font-semibold text-[#EA580C] mb-2">02. Technician visit</p>
                        <p class="text-xs sm:text-sm text-[#4B5563]">
                            A verified technician confirms timing, inspects on-site and explains the work needed before starting.
                        </p>
                    </div>
                    <div class="rounded-2xl bg-[#F9FAFB] border border-[#E5E7EB] p-4 sm:p-5">
                        <p class="text-xs font-semibold text-[#EA580C] mb-2">03. Completion &amp; review</p>
                        <p class="text-xs sm:text-sm text-[#4B5563]">
                            Once the job is completed, you can review the service so we can maintain standards across all categories.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- FOOTER -->
    <?php include '../components/footer.php'; ?>


    <!-- SCRIPTS -->
    <script src="./assets/js/jquery.min.js"></script>
    <script src="./assets/js/phosphor-icons.js"></script>
    <script src="./assets/js/slick.min.js"></script>
    <script src="./assets/js/leaflet.js"></script>
    <script src="./assets/js/swiper-bundle.min.js"></script>
    <script src="./assets/js/main.js"></script>

</body>
</html>
