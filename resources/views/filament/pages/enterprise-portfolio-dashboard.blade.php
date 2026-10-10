<x-filament-panels::page>
<div
      x-data="projectMap()"
      x-cloak
      class="enterprise-portfolio-map -mx-4 -mt-4 h-[calc(100vh-7rem)] min-h-[36rem] flex flex-col overflow-hidden bg-[#08080a] text-white sm:-mx-6"
    >
      <!-- ===================== HEADER ===================== -->
      <header
        class="px-6 py-3.5 flex items-center justify-between border-b border-white/[0.06] flex-shrink-0"
      >
        <div class="flex items-center gap-4 min-w-0">
          <span class="sr-only">Project Ecosystem Map</span>
          <div class="relative flex-shrink-0 text-sm font-semibold tracking-tight text-white/90">My Projects</div>

          <div class="h-5 w-px bg-white/15 flex-shrink-0"></div>

          <!-- Main dashboard filter -->
          <div class="relative flex-shrink-0" x-data="{ open: false, ddX: 0, ddY: 0, place() { const r = this.$refs.btn.getBoundingClientRect(); this.ddX = r.left; this.ddY = r.bottom + 4; } }">
            <button
              x-ref="btn"
              @click="open = !open; if (open) place()"
              @click.away="open = false"
              class="flex items-center gap-2 text-sm font-medium text-white/55 hover:text-white/85 transition-colors"
            >
              <span x-text="activeDashboard === 'projects' ? (activeStreamId === 'all' ? 'All Streams' : (streams.find(s => s.id == activeStreamId)?.title || 'All Streams')) : (activePropertyPurpose === 'all' ? 'All Purposes' : activePropertyPurpose)"></span>
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" :class="{ 'rotate-180': open }" class="transition-transform duration-150 text-white/40">
                <path d="m6 9 6 6 6-6" />
              </svg>
            </button>
            <template x-teleport="body">
              <div
                x-show="open"
                x-transition:enter="transition ease-out duration-100"
                x-transition:enter-start="opacity-0 -translate-y-1"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-75"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 -translate-y-1"
                :style="`position: fixed; left: ${ddX}px; top: ${ddY}px; z-index: 60;`"
                class="min-w-[160px] rounded-lg border border-white/10 bg-[#0e0e11] py-1 max-h-60 overflow-y-auto shadow-2xl"
                @click.away="open = false"
              >
                <template x-if="activeDashboard === 'projects'">
                  <div>
                    <button @click="setActiveStream('all'); open = false" class="w-full text-left px-3 py-1.5 text-xs hover:bg-white/5 transition-colors" :class="activeStreamId === 'all' ? 'text-white bg-white/5' : 'text-white/60'">All Streams</button>
                    <template x-for="stream in streams" :key="stream.id">
                      <button @click="setActiveStream(stream.id); open = false" class="w-full text-left px-3 py-1.5 text-xs hover:bg-white/5 transition-colors" :class="activeStreamId == stream.id ? 'text-white bg-white/5' : 'text-white/60'" x-text="stream.title"></button>
                    </template>
                  </div>
                </template>

              </div>
            </template>
          </div>
        </div>
        <div class="flex items-center gap-2 text-[11px] text-white/25">
          <span><span x-text="projects.length"></span> projects</span>
        </div>
      </header>

      <!-- ===================== PROJECTS DASHBOARD ===================== -->
      <template x-if="activeDashboard === 'projects'">
      <div class="contents">
      <!-- ===================== CONTROL BAR (search + filters) ===================== -->
      <div
        class="control-bar px-6 py-2.5 flex items-center justify-between gap-3 border-b border-white/[0.06] flex-shrink-0"
      >
        <!-- Search (left) -->
        <div class="relative search-wrapper flex-shrink-0">
          <svg
            x-show="mobileSearchOpen"
            class="absolute left-2.5 top-1/2 -translate-y-1/2 text-white/30 pointer-events-none"
            width="13"
            height="13"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
          >
            <circle cx="11" cy="11" r="8" />
            <path d="m21 21-4.3-4.3" />
          </svg>
          <input
            x-ref="projectsSearchInput"
            x-model="searchQuery"
            @keydown.enter="onProjectsSearchEnter()"
            @blur="if (!searchQuery) mobileSearchOpen = false"
            type="text"
            placeholder="Search projects... (⌘K)"
            class="search-input bg-white/[0.04] border border-white/10 rounded-lg pl-8 pr-3 py-1.5 text-xs text-white placeholder-white/30 focus:outline-none focus:bg-white/[0.06] w-52 transition-all duration-500 ease-out"
            :class="{ 'mobile-search-open': mobileSearchOpen }"
          />
          <button
            @click="mobileSearchOpen = true; $nextTick(() => $refs.projectsSearchInput.focus())"
            class="mobile-search-btn hidden items-center justify-center w-8 h-8 rounded-lg text-white/40 hover:text-white/70 transition-colors"
          >
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <circle cx="11" cy="11" r="8" />
              <path d="m21 21-4.3-4.3" />
            </svg>
          </button>
        </div>

        <!-- Filters (right) -->
        <div class="flex items-center gap-2 flex-shrink-0" :class="{ 'mobile-filter-hide': mobileSearchOpen }">
          <!-- Relation type filter dropdown -->
          <div class="relative" x-data="{ open: false, ddX: 0, ddY: 0, place() { const r = this.$refs.btn.getBoundingClientRect(); this.ddX = r.left; this.ddY = r.bottom + 4; } }">
            <button
              x-ref="btn"
              @click="open = !open; if (open) place()"
              @click.away="open = false"
              class="flex items-center gap-1.5 px-2.5 py-1 text-xs rounded-md bg-white/[0.04] border border-white/10 text-white/60 hover:text-white/90 hover:border-white/20 transition-colors"
            >
              <span class="text-white/35">Relation</span>
              <span class="flex items-center gap-1.5" x-show="activeRelationType !== 'all' && activeRelationType !== 'none'">
                <span class="w-1.5 h-1.5 rounded-full" :style="`background: ${relationColors[activeRelationType] || '#9ca3af'}`"></span>
              </span>
              <span x-text="activeRelationType === 'all' ? 'All' : activeRelationType === 'none' ? 'None' : activeRelationType.replace(/_/g, ' ')"></span>
              <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="m6 9 6 6 6-6" />
              </svg>
            </button>
            <template x-teleport="body">
              <div
                x-show="open"
                x-transition:enter="transition ease-out duration-100"
                x-transition:enter-start="opacity-0 -translate-y-1"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-75"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 -translate-y-1"
                :style="`position: fixed; left: ${ddX}px; top: ${ddY}px; z-index: 60;`"
                class="min-w-[160px] rounded-lg border border-white/10 bg-[#0e0e11] py-1 max-h-60 overflow-y-auto shadow-2xl"
                @click.away="open = false"
              >
                <button @click="setRelationType('all'); open = false" class="w-full text-left px-3 py-1.5 text-xs hover:bg-white/5 transition-colors" :class="activeRelationType === 'all' ? 'text-white bg-white/5' : 'text-white/60'">All</button>
                <button @click="setRelationType('none'); open = false" class="w-full text-left px-3 py-1.5 text-xs hover:bg-white/5 transition-colors" :class="activeRelationType === 'none' ? 'text-white bg-white/5' : 'text-white/60'">None</button>
                <div class="my-1 mx-2 h-px bg-white/[0.06]"></div>
                <template x-for="type in relationTypes" :key="type">
                  <button @click="setRelationType(type); open = false" class="w-full text-left px-3 py-1.5 text-xs hover:bg-white/5 transition-colors flex items-center gap-2 capitalize" :class="activeRelationType === type ? 'text-white bg-white/5' : 'text-white/60'"><span class="w-1.5 h-1.5 rounded-full" :style="`background: ${relationColors[type] || '#9ca3af'}`"></span><span x-text="type.replace(/_/g, ' ')"></span></button>
                </template>
              </div>
            </template>
          </div>

          <template x-for="field in filterableFields" :key="field">
            <div class="relative" x-data="{ open: false, ddX: 0, ddY: 0, place() { const r = this.$refs.btn.getBoundingClientRect(); this.ddX = r.left; this.ddY = r.bottom + 4; } }">
              <button
                x-ref="btn"
                @click="open = !open; if (open) place()"
                @click.away="open = false"
                class="flex items-center gap-1.5 px-2.5 py-1 text-xs rounded-md bg-white/[0.04] border border-white/10 text-white/60 hover:text-white/90 hover:border-white/20 transition-colors"
              >
                <span class="text-white/35 capitalize" x-text="field"></span>
                <span
                  x-text="(!projectFilters[field] || projectFilters[field] === 'all') ? 'All' : projectFilters[field]"
                ></span>
                <svg
                  width="10"
                  height="10"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="2"
                  stroke-linecap="round"
                >
                  <path d="m6 9 6 6 6-6" />
                </svg>
              </button>
              <template x-teleport="body">
                <div
                  x-show="open"
                  x-transition:enter="transition ease-out duration-100"
                  x-transition:enter-start="opacity-0 -translate-y-1"
                  x-transition:enter-end="opacity-100 translate-y-0"
                  x-transition:leave="transition ease-in duration-75"
                  x-transition:leave-start="opacity-100 translate-y-0"
                  x-transition:leave-end="opacity-0 -translate-y-1"
                  :style="`position: fixed; left: ${ddX}px; top: ${ddY}px; z-index: 60;`"
                  class="min-w-[160px] rounded-lg border border-white/10 bg-[#0e0e11] py-1 max-h-60 overflow-y-auto shadow-2xl"
                  @click.away="open = false"
                >
                  <button
                    @click="setProjectFilter(field, 'all'); open = false"
                    class="w-full text-left px-3 py-1.5 text-xs hover:bg-white/5 transition-colors"
                    :class="(!projectFilters[field] || projectFilters[field] === 'all') ? 'text-white bg-white/5' : 'text-white/60'"
                  >
                    All
                  </button>
                  <template x-for="value in fieldValues(field)" :key="value">
                    <button
                      @click="setProjectFilter(field, value); open = false"
                      class="w-full text-left px-3 py-1.5 text-xs hover:bg-white/5 transition-colors"
                      :class="projectFilters[field] === value ? 'text-white bg-white/5' : 'text-white/60'"
                      x-text="value"
                    ></button>
                  </template>
                </div>
              </template>
            </div>
          </template>
        </div>
      </div>

      <!-- ===================== VISUALIZATION ===================== -->
      <div class="flex-1 relative overflow-hidden">
        <div
          x-ref="scrollContainer"
          class="snap-scroll h-full overflow-auto"
        >
          <div
            x-ref="canvas"
            class="canvas-area relative dot-grid"
            :style="`min-width: ${minCanvasWidth}px`"
          >
            <!-- SVG overlay (behind cards) -->
            <svg
              class="absolute inset-0 w-full h-full z-0"
              style="pointer-events: none; overflow: visible;"
              x-ref="svg"
            >
              <defs>
                <marker
                  id="arrow-client"
                  viewBox="0 0 10 10"
                  refX="8"
                  refY="5"
                  markerWidth="5"
                  markerHeight="5"
                  orient="auto-start-reverse"
                  markerUnits="userSpaceOnUse"
                >
                  <path d="M 0 2 L 8 5 L 0 8 z" fill="#3b82f6" />
                </marker>
                <marker
                  id="arrow-partner"
                  viewBox="0 0 10 10"
                  refX="8"
                  refY="5"
                  markerWidth="5"
                  markerHeight="5"
                  orient="auto-start-reverse"
                  markerUnits="userSpaceOnUse"
                >
                  <path d="M 0 2 L 8 5 L 0 8 z" fill="#ec4899" />
                </marker>
                <marker
                  id="arrow-supplier"
                  viewBox="0 0 10 10"
                  refX="8"
                  refY="5"
                  markerWidth="5"
                  markerHeight="5"
                  orient="auto-start-reverse"
                  markerUnits="userSpaceOnUse"
                >
                  <path d="M 0 2 L 8 5 L 0 8 z" fill="#22c55e" />
                </marker>
                <marker
                  id="arrow-product"
                  viewBox="0 0 10 10"
                  refX="8"
                  refY="5"
                  markerWidth="5"
                  markerHeight="5"
                  orient="auto-start-reverse"
                  markerUnits="userSpaceOnUse"
                >
                  <path d="M 0 2 L 8 5 L 0 8 z" fill="#f59e0b" />
                </marker>
                <marker id="arrow-depends_on" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="5" markerHeight="5" orient="auto-start-reverse" markerUnits="userSpaceOnUse"><path d="M 0 2 L 8 5 L 0 8 z" fill="#f59e0b" /></marker>
                <marker id="arrow-supports" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="5" markerHeight="5" orient="auto-start-reverse" markerUnits="userSpaceOnUse"><path d="M 0 2 L 8 5 L 0 8 z" fill="#22c55e" /></marker>
                <marker id="arrow-integrates_with" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="5" markerHeight="5" orient="auto-start-reverse" markerUnits="userSpaceOnUse"><path d="M 0 2 L 8 5 L 0 8 z" fill="#3b82f6" /></marker>
                <marker id="arrow-related_to" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="5" markerHeight="5" orient="auto-start-reverse" markerUnits="userSpaceOnUse"><path d="M 0 2 L 8 5 L 0 8 z" fill="#9ca3af" /></marker>
                <marker id="arrow-competes_with" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="5" markerHeight="5" orient="auto-start-reverse" markerUnits="userSpaceOnUse"><path d="M 0 2 L 8 5 L 0 8 z" fill="#ec4899" /></marker>
                <marker id="arrow-owns" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="5" markerHeight="5" orient="auto-start-reverse" markerUnits="userSpaceOnUse"><path d="M 0 2 L 8 5 L 0 8 z" fill="#a78bfa" /></marker>
              </defs>
              <g x-ref="pathsGroup"></g>
            </svg>

            <!-- Streams -->
            <div
              class="streams-container relative z-10 flex gap-6 pt-2 pb-12 px-6"
              :style="`min-width: ${minCanvasWidth}px`"
              @click="handleCanvasClick($event)"
            >
              <template x-for="stream in visibleStreams" :key="stream.id">
                <div
                  class="stream-col relative"
                  :class="isSingleStream ? 'w-full max-w-[1400px] mx-auto' : 'flex-1'"
                  :style="isSingleStream ? '' : `min-width: ${minStreamWidth}px;`"
                >
                  <!-- Vertical guide line -->
                  <div
                    class="absolute left-1/2 -translate-x-1/2 top-20 bottom-0 w-px bg-gradient-to-b from-white/[0.04] via-white/[0.015] to-transparent pointer-events-none"
                  ></div>

                  <!-- Stream header -->
                  <div class="stream-header text-center pt-4">
                    <div
                      class="text-[11px] font-medium uppercase tracking-[0.2em] text-white/35"
                      x-text="stream.title"
                    ></div>
                    <div
                      class="text-[10px] text-white/20 mt-0.5"
                      x-text="stream.description"
                    ></div>
                  </div>

                  <!-- Projects -->
                  <div class="relative" :class="isSingleStream ? 'grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4' : 'space-y-4'">
                    <template
                      x-for="project in projectsByStream(stream.id)"
                      :key="project.project_id"
                    >
                      <div
                        :data-project-id="project.project_id"
                        @mouseenter="hoverProject(project)"
                        @mouseleave="leaveProject()"
                        class="group relative rounded-lg border border-white/[0.08] bg-white/[0.025] px-4 py-3 transition-all duration-200 hover:border-white/20 hover:bg-white/[0.04]"
                        :class="{
                          'opacity-20': isProjectDimmed(project),
                          'border-white/25 bg-white/[0.05] scale-[1.03]': isProjectActive(project.project_id),
                          'border-white/15 bg-white/[0.03]': isProjectConnected(project.project_id) && !isProjectActive(project.project_id),
                        }"
                      >
                        <!-- Event indicator -->
                        <div
                          x-show="latestEventForProject(project.project_id)"
                          class="absolute top-2.5 right-2.5 flex items-center gap-1.5 cursor-help"
                          @mouseenter="hoverEvent(project.project_id, $event)"
                          @mouseleave="leaveEvent()"
                        >
                          <span
                            class="relative flex items-center justify-center"
                          >
                            <span
                              class="absolute inline-flex h-2 w-2 rounded-full opacity-60 animate-ping"
                              :style="`background: ${eventColorForProject(project.project_id)}`"
                            ></span>
                            <span
                              class="relative inline-flex h-1.5 w-1.5 rounded-full transition-colors duration-500"
                              :style="`background: ${eventColorForProject(project.project_id)}`"
                            ></span>
                          </span>
                          <span class="text-[9px] text-white/35" x-text="eventLabelForProject(project.project_id)"></span>
                        </div>
                        <div
                          class="text-[10px] uppercase tracking-wider text-white/35 font-medium"
                          x-text="project.category"
                        ></div>
                        <div class="flex items-baseline gap-2 mt-1.5">
                          <div
                            class="text-[13px] font-semibold text-white/90 cursor-pointer hover:text-white"
                            x-text="project.name"
                            @click.stop="selectProject(project)"
                          ></div>
                          <div
                            class="text-[10px] text-white/25"
                            x-text="'→ ' + project.target"
                          ></div>
                        </div>
                        <div
                          class="text-[11px] text-white/40 leading-relaxed mt-1"
                          x-text="project.description"
                        ></div>
                        <button
                          @click.stop="openIssuesModal(project)"
                          class="mt-2.5 w-full flex items-center gap-1.5 rounded-md border border-white/10 bg-white/[0.02] px-2 py-1.5 text-[10px] text-white/50 hover:bg-white/[0.06] hover:text-white/70 hover:border-white/20 transition-all duration-150"
                        >
                          <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" class="flex-shrink-0">
                            <path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12" />
                          </svg>
                          <span class="flex-1 text-left">Open GitHub issues</span>
                          <span class="font-semibold text-white/70" x-text="issueCountForProject(project.project_id)"></span>
                        </button>
                        <button
                          @click.stop="openPostsModal(project)"
                          class="mt-1 w-full flex items-center gap-1.5 rounded-md border border-white/10 bg-white/[0.02] px-2 py-1.5 text-[10px] text-white/50 hover:bg-white/[0.06] hover:text-white/70 hover:border-white/20 transition-all duration-150"
                        >
                          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="flex-shrink-0">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                            <line x1="16" y1="2" x2="16" y2="6"/>
                            <line x1="8" y1="2" x2="8" y2="6"/>
                            <line x1="3" y1="10" x2="21" y2="10"/>
                            <path d="M12 14l3 3 5-5"/>
                          </svg>
                          <span class="flex-1 text-left">Scheduled publications</span>
                          <span class="font-semibold text-white/70" x-text="postCountForProject(project.project_id)"></span>
                        </button>
                        <button
                          @click.stop="openTasksModal(project)"
                          class="mt-1 w-full flex items-center gap-1.5 rounded-md border border-white/10 bg-white/[0.02] px-2 py-1.5 text-[10px] text-white/50 hover:bg-white/[0.06] hover:text-white/70 hover:border-white/20 transition-all duration-150"
                        >
                          <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" class="flex-shrink-0">
                            <path d="M4.553 0L4.553 24 19.447 24 19.447 5.993 13.454 0 4.553 0zM13.454 0L13.454 5.993 19.447 5.993 13.454 0zM7.714 9.545L11.571 9.545 11.571 10.818 7.714 10.818 7.714 9.545zM7.714 12.273L16.286 12.273 16.286 13.545 7.714 13.545 7.714 12.273zM7.714 15L16.286 15 16.286 16.273 7.714 16.273 7.714 15zM7.714 17.727L16.286 17.727 16.286 19 7.714 19 7.714 17.727z"/>
                          </svg>
                          <span class="flex-1 text-left">Open tasks</span>
                          <span class="font-semibold text-white/70" x-text="taskCountForProject(project.project_id)"></span>
                        </button>
                        <button
                          @click.stop="openWorkflowsModal(project)"
                          class="mt-1 w-full flex items-center gap-1.5 rounded-md border border-white/10 bg-white/[0.02] px-2 py-1.5 text-[10px] text-white/50 hover:bg-white/[0.06] hover:text-white/70 hover:border-white/20 transition-all duration-150"
                        >
                          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="flex-shrink-0">
                            <rect x="2" y="2" width="6" height="6" rx="1"/>
                            <rect x="16" y="2" width="6" height="6" rx="1"/>
                            <rect x="2" y="16" width="6" height="6" rx="1"/>
                            <rect x="16" y="16" width="6" height="6" rx="1"/>
                            <path d="M8 5h8M8 19h8M5 8v8M19 8v8"/>
                          </svg>
                          <span class="flex-1 text-left">Workflow executions</span>
                          <span class="font-semibold text-white/70" x-text="workflowCountForProject(project.project_id)"></span>
                        </button>

                        <!-- Configured social accounts; counts are account records, not follower metrics. -->
                        <div class="mt-2.5 grid grid-cols-4 gap-1.5">
                          <template x-for="network in socialNetworks" :key="network">
                            <span
                              :title="`${network}: ${socialAccountCountForProject(project.project_id, network)} active account(s)`"
                              class="flex items-center gap-1 rounded-md border border-white/[0.06] bg-white/[0.02] px-1.5 py-1 transition-all duration-150"
                              :class="{ 'opacity-50': socialAccountCountForProject(project.project_id, network) === 0 }"
                            >
                              <span class="flex-shrink-0 text-white" x-html="socialIconSvg(network)"></span>
                              <span class="text-[9px] text-white/60 font-medium" x-text="formatFollowers(socialAccountCountForProject(project.project_id, network))"></span>
                            </span>
                          </template>
                        </div>
                      </div>
                    </template>
                  </div>
                </div>
              </template>
            </div>
          </div>
        </div>
      </div>

      <!-- ===================== INFO MODAL ===================== -->
      <div
        x-show="selectedProject"
        @click.self="reset()"
        @keydown.escape.window="reset()"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 backdrop-blur-sm"
      >
        <div
          x-show="selectedProject"
          x-transition:enter="transition ease-out duration-200"
          x-transition:enter-start="opacity-0 scale-95"
          x-transition:enter-end="opacity-100 scale-100"
          x-transition:leave="transition ease-in duration-150"
          x-transition:leave-start="opacity-100 scale-100"
          x-transition:leave-end="opacity-0 scale-95"
          @click.stop
          class="w-80 rounded-xl border border-white/10 bg-[#0e0e11]/95 backdrop-blur-md p-5 shadow-2xl"
        >
          <div class="flex items-start justify-between mb-3">
            <div>
              <div
                class="text-[10px] uppercase tracking-wider text-white/35 font-medium"
                x-text="selectedProject?.category"
              ></div>
              <div
                class="text-base font-semibold text-white mt-0.5"
                x-text="selectedProject?.name"
              ></div>
            </div>
            <button
              @click.stop="reset()"
              class="text-white/40 hover:text-white/80 transition-colors -mt-0.5"
            >
              <svg
                width="14"
                height="14"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
              >
                <path d="M18 6L6 18M6 6l12 12" />
              </svg>
            </button>
          </div>
          <div
            class="text-xs text-white/50 leading-relaxed mb-3"
            x-text="selectedProject?.description"
          ></div>
          <div class="flex items-center gap-2 text-[11px] text-white/40 mb-3">
            <span class="text-white/25">Target:</span>
            <span x-text="selectedProject?.target"></span>
          </div>
          <div class="border-t border-white/5 pt-3">
            <div
              class="text-[10px] uppercase tracking-wider text-white/25 mb-2"
            >
              Connected Projects
            </div>
            <div class="space-y-1.5 max-h-40 overflow-y-auto px-1">
              <template
                x-for="conn in connectedProjectsWithType(selectedProject?.project_id)"
                :key="conn.project.project_id"
              >
                <div class="space-y-0.5">
                  <div
                    class="text-xs text-white/50 flex items-center gap-2 group/conn cursor-pointer rounded px-1 py-0.5 transition-colors hover:bg-white/[0.03]"
                    @click.stop="toggleConnDescription(conn)"
                  >
                    <span
                      class="w-1.5 h-1.5 rounded-full flex-shrink-0"
                      :style="`background: ${relationColors[conn.type]}`"
                    ></span>
                    <span class="text-white/70 flex-1" x-text="conn.project.name"></span>
                    <span
                      class="text-[10px] uppercase tracking-wider px-1.5 py-0.5 rounded transition-transform duration-150 group-hover/conn:scale-105"
                      :style="`color: ${relationColors[conn.type]}; background: ${relationColors[conn.type]}15`"
                      x-text="conn.type"
                    ></span>
                    <svg
                      class="text-white/25 transition-transform duration-150 flex-shrink-0"
                      :class="{ 'rotate-180': expandedConns[conn.project.project_id + conn.type] }"
                      width="10"
                      height="10"
                      viewBox="0 0 24 24"
                      fill="none"
                      stroke="currentColor"
                      stroke-width="2.5"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    >
                      <path d="M6 9l6 6 6-6" />
                    </svg>
                  </div>
                  <div
                    x-show="expandedConns[conn.project.project_id + conn.type]"
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0 -translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    x-transition:leave="transition ease-in duration-100"
                    x-transition:leave-start="opacity-100 translate-y-0"
                    x-transition:leave-end="opacity-0 -translate-y-1"
                    class="text-[11px] text-white/40 pl-3.5 mt-1 leading-relaxed"
                    x-text="conn.description"
                  ></div>
                </div>
              </template>
              <div
                x-show="selectedProject && connectedProjectsWithType(selectedProject.project_id).length === 0"
                class="text-xs text-white/25"
              >
                No connections
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ===================== RELATION TOOLTIP ===================== -->
      <div
        x-show="hoveredRelation"
        :style="`position: fixed; left: ${tooltipX}px; top: ${tooltipY}px; z-index: 50; pointer-events: none;`"
        class="rounded-lg border border-white/10 bg-[#0e0e11]/95 backdrop-blur-md px-3 py-2 shadow-2xl max-w-[260px]"
      >
        <div
          class="text-[10px] uppercase tracking-wider font-medium"
          :style="`color: ${hoveredRelation ? relationColors[hoveredRelation.type] : '#fff'}`"
          x-text="hoveredRelation?.type"
        ></div>
        <div
          class="text-xs text-white/70 mt-1"
          x-text="hoveredRelation?.description"
        ></div>
        <div
          class="text-[11px] text-white/40 mt-1.5 flex items-center gap-1.5"
        >
          <span x-text="projectName(hoveredRelation?.project_id)"></span>
          <span class="text-white/30">→</span>
          <span x-text="projectName(hoveredRelation?.target_project_id)"></span>
        </div>
      </div>

      <!-- ===================== EVENT TOOLTIP ===================== -->
      <div
        x-show="hoveredEvent"
        :style="`position: fixed; left: ${eventTooltipX}px; top: ${eventTooltipY}px; z-index: 50; pointer-events: none;`"
        class="rounded-lg border border-white/10 bg-[#0e0e11]/95 backdrop-blur-md px-3 py-2.5 shadow-2xl max-w-[280px]"
      >
        <div class="flex items-center gap-2 mb-1.5">
          <span
            class="relative flex items-center justify-center"
          >
            <span
              class="absolute inline-flex h-2.5 w-2.5 rounded-full opacity-60 animate-ping"
              :style="`background: ${hoveredEvent ? eventColorForProject(hoveredEvent.project_id) : '#3b82f6'}`"
            ></span>
            <span
              class="relative inline-flex h-1.5 w-1.5 rounded-full"
              :style="`background: ${hoveredEvent ? eventColorForProject(hoveredEvent.project_id) : '#3b82f6'}`"
            ></span>
          </span>
          <span
            class="text-[10px] uppercase tracking-wider font-medium"
            :style="`color: ${hoveredEvent ? eventColorForProject(hoveredEvent.project_id) : '#3b82f6'}`"
            x-text="hoveredEvent ? eventLabelForProject(hoveredEvent.project_id) : ''"
          ></span>
        </div>
        <div
          class="text-xs text-white/80 leading-relaxed"
          x-text="hoveredEvent?.description"
        ></div>
        <div
          class="text-[11px] text-white/35 mt-1.5"
          x-text="hoveredEvent ? projectName(hoveredEvent.project_id) : ''"
        ></div>
      </div>

      <!-- ===================== SCHEDULED POSTS MODAL ===================== -->
      <div
        x-show="postsModalProject"
        @click.self="closePostsModal()"
        @keydown.escape.window="closePostsModal()"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm"
      >
        <div
          x-show="postsModalProject"
          x-transition:enter="transition ease-out duration-200"
          x-transition:enter-start="opacity-0 scale-95"
          x-transition:enter-end="opacity-100 scale-100"
          x-transition:leave="transition ease-in duration-150"
          x-transition:leave-start="opacity-100 scale-100"
          x-transition:leave-end="opacity-0 scale-95"
          @click.stop
          class="w-96 max-h-[80vh] rounded-xl border border-white/10 bg-[#0e0e11]/95 backdrop-blur-md p-5 shadow-2xl flex flex-col"
        >
          <div class="flex items-start justify-between mb-4">
            <div class="flex items-center gap-2.5">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-white/60">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                <line x1="16" y1="2" x2="16" y2="6"/>
                <line x1="8" y1="2" x2="8" y2="6"/>
                <line x1="3" y1="10" x2="21" y2="10"/>
                <path d="M12 14l3 3 5-5"/>
              </svg>
              <div>
                <div
                  class="text-[10px] uppercase tracking-wider text-white/35 font-medium"
                  x-text="postsModalProject?.category"
                ></div>
                <div
                  class="text-base font-semibold text-white mt-0.5"
                  x-text="postsModalProject?.name"
                ></div>
              </div>
            </div>
            <button
              @click.stop="closePostsModal()"
              class="text-white/40 hover:text-white/80 transition-colors -mt-0.5"
            >
              <svg
                width="14"
                height="14"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
              >
                <path d="M18 6L6 18M6 6l12 12" />
              </svg>
            </button>
          </div>
          <div class="text-[11px] text-white/40 mb-3">
            <span x-text="postCountForProject(postsModalProject?.project_id)"></span> scheduled publication<span x-show="postCountForProject(postsModalProject?.project_id) !== 1">s</span>
          </div>
          <div class="flex-1 overflow-y-auto space-y-2 pr-1 -mr-1">
            <template
              x-for="post in postsForProject(postsModalProject?.project_id)"
              :key="post.post_id"
            >
              <div
                class="block rounded-lg border border-white/10 bg-white/[0.02] p-3 hover:bg-white/[0.05] hover:border-white/20 transition-all duration-150"
              >
                <div class="flex items-start gap-2.5">
                  <div
                    class="flex-shrink-0 mt-0.5 w-5 h-5 rounded-full flex items-center justify-center"
                    :style="`background: ${platformColor(post.platform)}20; color: ${platformColor(post.platform)}`"
                    x-html="platformIconSvg(post.platform)"
                  ></div>
                  <div class="flex-1 min-w-0">
                    <div class="text-xs font-medium text-white/80 leading-snug" x-text="post.content"></div>
                    <div class="flex items-center gap-2 mt-1.5 flex-wrap">
                      <span
                        class="text-[9px] px-1.5 py-0.5 rounded-full font-medium flex items-center gap-1"
                        :style="`background: ${platformColor(post.platform)}20; color: ${platformColor(post.platform)}`"
                      >
                        <span x-html="platformIconSvg(post.platform)"></span>
                        <span x-text="post.platform"></span>
                      </span>
                      <span class="text-[10px] text-white/30" x-text="post.scheduled_at"></span>
                      <span class="text-[10px] text-white/30" x-text="post.status"></span>
                    </div>
                  </div>
                </div>
              </div>
            </template>
            <div
              x-show="postsModalProject && postCountForProject(postsModalProject.project_id) === 0"
              class="text-xs text-white/25 text-center py-8"
            >
              No upcoming scheduled posts
            </div>
          </div>
        </div>
      </div>

      <!-- ===================== NOTION TASKS MODAL ===================== -->
      <div
        x-show="tasksModalProject"
        @click.self="closeTasksModal()"
        @keydown.escape.window="closeTasksModal()"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm"
      >
        <div
          x-show="tasksModalProject"
          x-transition:enter="transition ease-out duration-200"
          x-transition:enter-start="opacity-0 scale-95"
          x-transition:enter-end="opacity-100 scale-100"
          x-transition:leave="transition ease-in duration-150"
          x-transition:leave-start="opacity-100 scale-100"
          x-transition:leave-end="opacity-0 scale-95"
          @click.stop
          class="w-96 max-h-[80vh] rounded-xl border border-white/10 bg-[#0e0e11]/95 backdrop-blur-md p-5 shadow-2xl flex flex-col"
        >
          <div class="flex items-start justify-between mb-4">
            <div class="flex items-center gap-2.5">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" class="text-white/60">
                <path d="M4.553 0L4.553 24 19.447 24 19.447 5.993 13.454 0 4.553 0zM13.454 0L13.454 5.993 19.447 5.993 13.454 0zM7.714 9.545L11.571 9.545 11.571 10.818 7.714 10.818 7.714 9.545zM7.714 12.273L16.286 12.273 16.286 13.545 7.714 13.545 7.714 12.273zM7.714 15L16.286 15 16.286 16.273 7.714 16.273 7.714 15zM7.714 17.727L16.286 17.727 16.286 19 7.714 19 7.714 17.727z"/>
              </svg>
              <div>
                <div
                  class="text-[10px] uppercase tracking-wider text-white/35 font-medium"
                  x-text="tasksModalProject?.category"
                ></div>
                <div
                  class="text-base font-semibold text-white mt-0.5"
                  x-text="tasksModalProject?.name"
                ></div>
              </div>
            </div>
            <button
              @click.stop="closeTasksModal()"
              class="text-white/40 hover:text-white/80 transition-colors -mt-0.5"
            >
              <svg
                width="14"
                height="14"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
              >
                <path d="M18 6L6 18M6 6l12 12" />
              </svg>
            </button>
          </div>
          <div class="text-[11px] text-white/40 mb-3">
            <span x-text="taskCountForProject(tasksModalProject?.project_id)"></span> open task<span x-show="taskCountForProject(tasksModalProject?.project_id) !== 1">s</span>
          </div>
          <div class="flex-1 overflow-y-auto space-y-2 pr-1 -mr-1">
            <template
              x-for="task in tasksForProject(tasksModalProject?.project_id)"
              :key="task.task_id"
            >
              <div
                class="block rounded-lg border border-white/10 bg-white/[0.02] p-3 hover:bg-white/[0.05] hover:border-white/20 transition-all duration-150"
              >
                <div class="flex items-start gap-2.5">
                  <div
                    class="flex-shrink-0 mt-0.5 w-5 h-5 rounded-full flex items-center justify-center text-[10px] font-semibold"
                    :style="`background: ${taskPriorityColor(task.priority)}20; color: ${taskPriorityColor(task.priority)}`"
                    x-text="task.priority.charAt(0).toUpperCase()"
                  ></div>
                  <div class="flex-1 min-w-0">
                    <div class="text-xs font-medium text-white/80 leading-snug" x-text="task.title"></div>
                    <div class="flex items-center gap-2 mt-1.5 flex-wrap">
                      <span
                        class="text-[9px] px-1.5 py-0.5 rounded-full font-medium"
                        :style="`background: ${taskPriorityColor(task.priority)}20; color: ${taskPriorityColor(task.priority)}`"
                        x-text="task.priority"
                      ></span>
                      <span class="text-[10px] text-white/30" x-text="task.assignee"></span>
                      <span class="text-[10px] text-white/30" x-text="task.due_date"></span>
                    </div>
                  </div>
                </div>
              </div>
            </template>
            <div
              x-show="tasksModalProject && taskCountForProject(tasksModalProject.project_id) === 0"
              class="text-xs text-white/25 text-center py-8"
            >
              No open tasks
            </div>
          </div>
        </div>
      </div>

      <!-- ===================== GITHUB ISSUES MODAL ===================== -->
      <div
        x-show="issuesModalProject"
        @click.self="closeIssuesModal()"
        @keydown.escape.window="closeIssuesModal()"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm"
      >
        <div
          x-show="issuesModalProject"
          x-transition:enter="transition ease-out duration-200"
          x-transition:enter-start="opacity-0 scale-95"
          x-transition:enter-end="opacity-100 scale-100"
          x-transition:leave="transition ease-in duration-150"
          x-transition:leave-start="opacity-100 scale-100"
          x-transition:leave-end="opacity-0 scale-95"
          @click.stop
          class="w-96 max-h-[80vh] rounded-xl border border-white/10 bg-[#0e0e11]/95 backdrop-blur-md p-5 shadow-2xl flex flex-col"
        >
          <div class="flex items-start justify-between mb-4">
            <div class="flex items-center gap-2.5">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" class="text-white/60">
                <path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12" />
              </svg>
              <div>
                <div
                  class="text-[10px] uppercase tracking-wider text-white/35 font-medium"
                  x-text="issuesModalProject?.category"
                ></div>
                <div
                  class="text-base font-semibold text-white mt-0.5"
                  x-text="issuesModalProject?.name"
                ></div>
              </div>
            </div>
            <button
              @click.stop="closeIssuesModal()"
              class="text-white/40 hover:text-white/80 transition-colors -mt-0.5"
            >
              <svg
                width="14"
                height="14"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
              >
                <path d="M18 6L6 18M6 6l12 12" />
              </svg>
            </button>
          </div>
          <div class="text-[11px] text-white/40 mb-3">
            <span x-text="issueCountForProject(issuesModalProject?.project_id)"></span> open issue<span x-show="issueCountForProject(issuesModalProject?.project_id) !== 1">s</span>
          </div>
          <div class="flex-1 overflow-y-auto space-y-2 pr-1 -mr-1">
            <template
              x-for="issue in issuesForProject(issuesModalProject?.project_id)"
              :key="issue.issue_id"
            >
              <a
                :href="issue.url"
                target="_blank"
                rel="noopener noreferrer"
                class="block rounded-lg border border-white/10 bg-white/[0.02] p-3 hover:bg-white/[0.05] hover:border-white/20 transition-all duration-150"
              >
                <div class="flex items-start gap-2.5">
                  <div
                    class="flex-shrink-0 mt-0.5 w-5 h-5 rounded-full flex items-center justify-center text-[10px] font-semibold"
                    :style="`background: ${issue.state === 'open' ? '#22c55e20' : '#a855f720'}; color: ${issue.state === 'open' ? '#22c55e' : '#a855f7'}`"
                    x-text="issue.state === 'open' ? 'O' : 'C'"
                  ></div>
                  <div class="flex-1 min-w-0">
                    <div class="text-xs font-medium text-white/80 leading-snug" x-text="issue.title"></div>
                    <div class="flex items-center gap-2 mt-1.5 flex-wrap">
                      <template x-for="label in issue.labels" :key="label">
                        <span
                          class="text-[9px] px-1.5 py-0.5 rounded-full font-medium"
                            :style="`background: ${labelColor(label)}20; color: ${labelColor(label)}`"
                            x-text="label"
                        ></span>
                      </template>
                      <span class="text-[10px] text-white/30" x-text="'#' + issue.number"></span>
                      <span class="text-[10px] text-white/30" x-text="issue.author"></span>
                      <span class="text-[10px] text-white/30" x-text="issue.created_at"></span>
                    </div>
                  </div>
                </div>
              </a>
            </template>
            <div
              x-show="issuesModalProject && issueCountForProject(issuesModalProject.project_id) === 0"
              class="text-xs text-white/25 text-center py-8"
            >
              No open issues
            </div>
          </div>
        </div>
      </div>

      <!-- ===================== N8N WORKFLOWS MODAL ===================== -->
      <div
        x-show="workflowsModalProject"
        @click.self="closeWorkflowsModal()"
        @keydown.escape.window="closeWorkflowsModal()"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm"
      >
        <div
          x-show="workflowsModalProject"
          x-transition:enter="transition ease-out duration-200"
          x-transition:enter-start="opacity-0 scale-95"
          x-transition:enter-end="opacity-100 scale-100"
          x-transition:leave="transition ease-in duration-150"
          x-transition:leave-start="opacity-100 scale-100"
          x-transition:leave-end="opacity-0 scale-95"
          @click.stop
          class="w-96 max-h-[80vh] rounded-xl border border-white/10 bg-[#0e0e11]/95 backdrop-blur-md p-5 shadow-2xl flex flex-col"
        >
          <div class="flex items-start justify-between mb-4">
            <div class="flex items-center gap-2.5">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-white/60">
                <rect x="2" y="2" width="6" height="6" rx="1"/>
                <rect x="16" y="2" width="6" height="6" rx="1"/>
                <rect x="2" y="16" width="6" height="6" rx="1"/>
                <rect x="16" y="16" width="6" height="6" rx="1"/>
                <path d="M8 5h8M8 19h8M5 8v8M19 8v8"/>
              </svg>
              <div>
                <div class="text-[10px] uppercase tracking-wider text-white/35 font-medium" x-text="workflowsModalProject?.category"></div>
                <div class="text-base font-semibold text-white mt-0.5" x-text="workflowsModalProject?.name"></div>
              </div>
            </div>
            <button @click.stop="closeWorkflowsModal()" class="text-white/40 hover:text-white/80 transition-colors -mt-0.5">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M18 6L6 18M6 6l12 12" />
              </svg>
            </button>
          </div>
          <div class="text-[11px] text-white/40 mb-3">
            <span x-text="workflowCountForProject(workflowsModalProject?.project_id)"></span> workflow execution<span x-show="workflowCountForProject(workflowsModalProject?.project_id) !== 1">s</span>
          </div>
          <div class="flex-1 overflow-y-auto space-y-2 pr-1 -mr-1">
            <template x-for="wf in workflowsForProject(workflowsModalProject?.project_id)" :key="wf.workflow_id">
              <div class="block rounded-lg border border-white/10 bg-white/[0.02] p-3 hover:bg-white/[0.05] hover:border-white/20 transition-all duration-150">
                <div class="flex items-start gap-2.5">
                  <div class="flex-shrink-0 mt-0.5 w-5 h-5 rounded-full flex items-center justify-center bg-green-500/20 text-green-400">
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                      <polyline points="20 6 9 17 4 12"/>
                    </svg>
                  </div>
                  <div class="flex-1 min-w-0">
                    <div class="text-xs font-medium text-white/80 leading-snug" x-text="wf.name"></div>
                    <div class="flex items-center gap-2 mt-1.5 flex-wrap">
                      <span class="text-[9px] px-1.5 py-0.5 rounded-full font-medium bg-white/5 text-white/50" x-text="wf.trigger"></span>
                      <span class="text-[10px] text-white/30" x-text="'Last run ' + wf.last_run"></span>
                    </div>
                  </div>
                </div>
              </div>
            </template>
            <div x-show="workflowsModalProject && workflowCountForProject(workflowsModalProject.project_id) === 0" class="text-xs text-white/25 text-center py-8">
              No scheduled workflows
            </div>
          </div>
        </div>
      </div>

      <!-- ===================== REFRESH BAR ===================== -->
      <div class="flex-shrink-0 flex items-center justify-between px-6 py-4 border-t border-white/[0.06] bg-[#0a0a0d]">
        <div class="flex items-center gap-2.5">
          <div class="relative w-4 h-4">
            <svg class="w-4 h-4 -rotate-90" viewBox="0 0 16 16">
              <circle cx="8" cy="8" r="6" fill="none" stroke="rgba(255,255,255,0.08)" stroke-width="2"/>
              <circle cx="8" cy="8" r="6" fill="none" stroke="#3b82f6" stroke-width="2" stroke-linecap="round"
                :stroke-dasharray="2 * Math.PI * 6"
                :stroke-dashoffset="2 * Math.PI * 6 * (1 - refreshProgressPercent / 100)"
                style="transition: stroke-dashoffset 1s linear;"
              />
            </svg>
          </div>
          <span class="text-[11px] text-white/40">
            Next refresh in
            <span class="text-white/80 font-medium tabular-nums" x-text="refreshCountdownDisplay"></span>
          </span>
          <span class="text-[10px] text-white/20">·</span>
          <span class="text-[10px] text-white/25">
            Last: <span x-text="lastRefreshedDisplay"></span>
          </span>
        </div>
        <button
          @click="manualRefresh()"
          :disabled="isRefreshing"
          :class="isRefreshing ? 'opacity-50 cursor-not-allowed' : 'hover:bg-white/10 hover:text-white/90'"
          class="flex items-center gap-1.5 px-3 py-1 text-[11px] text-white/50 rounded-md border border-white/10 bg-white/[0.04] transition-colors"
        >
          <svg
            class="w-3 h-3"
            :class="isRefreshing ? 'animate-spin' : ''"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
          >
            <path d="M21 12a9 9 0 1 1-2.64-6.36L21 8" />
            <path d="M21 3v5h-5" />
          </svg>
          <span x-text="isRefreshing ? 'Refreshing...' : 'Refresh now'"></span>
        </button>
      </div>
      </div>
      </template>
      <!-- ===================== END PROJECTS DASHBOARD ===================== -->
    </div>

    <script>
      const streams = @js($dashboardStreams);
      const projects = @js($dashboardProjects);
      const relations = @js($dashboardRelations);
      const events = @js($dashboardEvents);
      const githubIssues = @js($dashboardIssues);
      const scheduledPosts = @js($dashboardScheduledPosts);
      const notionTasks = @js($dashboardTasks);
      const n8nWorkflows = @js($dashboardWorkflows);
      const propertyDocuments = [];
      const labelColorMap = { bug: '#ef4444', enhancement: '#3b82f6', critical: '#f97316', ui: '#a78bfa', mobile: '#f59e0b', seo: '#22c55e' };
      const platformColorMap = {};
      const taskPriorityColorMap = { high: '#ef4444', medium: '#f59e0b', low: '#22c55e' };
      const socialNetworks = @js($dashboardSocialNetworks);
      const socialFollowers = @js($dashboardSocialFollowers);
      const properties = [];
      const tenants = [];
      const utilityInvoices = [];
      const propertyCosts = [];
      const propertyRevenues = [];
      const propertyTypes = [];
      const propertyPurposes = [];
      const utilityTypes = [];
    </script>

    <script>
      function projectMap() {
        return {
          // ---- DATA ----
          streams: streams,
          projects: projects,
          relations: relations,
          events: events,
          githubIssues: githubIssues,
          scheduledPosts: scheduledPosts,
          notionTasks: notionTasks,
          n8nWorkflows: n8nWorkflows,
          propertyDocuments: propertyDocuments,
          labelColorMap: labelColorMap,
          platformColorMap: platformColorMap,
          taskPriorityColorMap: taskPriorityColorMap,
          socialNetworks: socialNetworks,
          socialFollowers: socialFollowers,
          properties: properties,
          tenants: tenants,
          utilityInvoices: utilityInvoices,
          propertyCosts: propertyCosts,
          propertyRevenues: propertyRevenues,
          propertyTypes: propertyTypes,
          propertyPurposes: propertyPurposes,
          utilityTypes: utilityTypes,

          // ---- CONFIG ----
          minStreamWidth: 250,
          relationColors: {
            depends_on: '#f59e0b',
            supports: '#22c55e',
            integrates_with: '#3b82f6',
            related_to: '#9ca3af',
            competes_with: '#ec4899',
            owns: '#a78bfa',
          },
          propertyTypeColors: {
            Apartment: '#3b82f6',
            House: '#22c55e',
            Office: '#8b5cf6',
            Retail: '#f59e0b',
            Warehouse: '#f97316',
            Land: '#14b8a6',
          },

          // ---- STATE ----
          activeDashboard: 'projects',
          selectedProject: null,
          hoveredProject: null,
          hoveredRelation: null,
          hoveredEvent: null,
          searchQuery: '',
          mobileSearchOpen: false,
          activeRelationType: 'all',
          activeStreamId: 'all',
          projectFilters: {},
          tooltipX: 0,
          tooltipY: 0,
          eventTooltipX: 0,
          eventTooltipY: 0,
          expandedConns: {},
          issuesModalProject: null,
          postsModalProject: null,
          tasksModalProject: null,
          workflowsModalProject: null,
          documentsModalProperty: null,
          tenantsModalProperty: null,
          invoicesModalProperty: null,
          _eventTimer: null,
          refreshIntervalSeconds: 300,
          refreshCountdown: 300,
          isRefreshing: false,
          lastRefreshedAt: null,
          _refreshTimer: null,

          // ---- PROPERTIES STATE ----
          propertySearchQuery: '',
          activePropertyType: 'all',
          activePropertyPurpose: 'all',
          activeUtilityType: 'all',
          activeUtilitySupplier: 'all',
          chartAggregation: 'month',
          selectedProperty: null,
          searchResultProject: null,
          searchResultProperty: null,

          // ---- INTERNAL ----
          _recalcRAF: null,
          _resizeObserver: null,

          // ---- COMPUTED ----
          get minCanvasWidth() {
            const gap = 24;
            const count = this.visibleStreams.length;
            return count * this.minStreamWidth + (count - 1) * gap + 48;
          },

          get visibleStreams() {
            if (this.activeStreamId === 'all') return this.streams;
            return this.streams.filter((s) => s.id === this.activeStreamId);
          },

          get isSingleStream() {
            return this.activeStreamId !== 'all';
          },

          get relationTypes() {
            return [...new Set(this.relations.map((relation) => relation.type).filter(Boolean))].sort();
          },

          get filterableFields() {
            const excluded = ['project_id', 'stream_id', 'name', 'description'];
            if (!this.projects.length) return [];
            return Object.keys(this.projects[0]).filter((k) => !excluded.includes(k));
          },

          get hasActiveProjectFilters() {
            return this.filterableFields.some(
              (f) => this.projectFilters[f] && this.projectFilters[f] !== 'all'
            );
          },

          fieldValues(field) {
            const values = [
              ...new Set(this.projects.map((p) => p[field]).filter((v) => v != null)),
            ];
            return values.sort();
          },

          setRelationType(type) {
            this.activeRelationType = type;
            this.updatePathStyles();
          },

          setActiveStream(id) {
            this.activeStreamId = id;
            this.$nextTick(() => {
              this.recalculatePaths();
              this.updatePathStyles();
            });
          },

          setProjectFilter(field, value) {
            this.projectFilters[field] = value;
            this.updatePathStyles();
          },

          projectMatchesFilters(project) {
            for (const field of this.filterableFields) {
              const selected = this.projectFilters[field];
              if (selected && selected !== 'all' && project[field] !== selected) {
                return false;
              }
            }
            return true;
          },

          projectMatchesFiltersById(id) {
            const project = this.projects.find((p) => p.project_id === id);
            return project ? this.projectMatchesFilters(project) : false;
          },

          // ---- LIFECYCLE ----
          init() {
            this.filterableFields.forEach((f) => {
              this.projectFilters[f] = 'all';
            });
            this._eventTimer = setInterval(() => {
              this.$nextTick(() => this.$forceUpdate());
            }, 30000);
            this.lastRefreshedAt = new Date();
            this._refreshTimer = setInterval(() => {
              this.refreshCountdown--;
              if (this.refreshCountdown <= 0) {
                this.doRefresh();
              }
            }, 1000);
            this.$nextTick(() => {
              this.createPaths();
              this.recalculatePaths();
              this.setupObservers();
              this.setupGestureLock();
            });

            window.addEventListener('keydown', (e) => {
              if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                this.mobileSearchOpen = true;
                this.$nextTick(() => {
                  if (this.activeDashboard === 'projects') {
                    this.$refs.projectsSearchInput?.focus();
                  } else {
                    this.$refs.projectsSearchInput?.focus();
                  }
                });
              }
            });

            if (document.fonts && document.fonts.ready) {
              document.fonts.ready.then(() => {
                this.$nextTick(() => this.recalculatePaths());
              });
            }
          },

          // ---- GESTURE LOCK (touch axis lock) ----
          setupGestureLock() {
            const sc = this.$refs.scrollContainer;
            if (!sc) return;

            let startX = 0, startY = 0, axis = null;

            sc.addEventListener('touchstart', (e) => {
              if (e.touches.length === 1) {
                startX = e.touches[0].clientX;
                startY = e.touches[0].clientY;
                axis = null;
              }
            }, { passive: true });

            sc.addEventListener('touchmove', (e) => {
              if (e.touches.length !== 1) return;
              if (axis === null) {
                const dx = Math.abs(e.touches[0].clientX - startX);
                const dy = Math.abs(e.touches[0].clientY - startY);
                if (dx < 6 && dy < 6) return;
                axis = dx > dy ? 'x' : 'y';
                if (axis === 'x') {
                  sc.style.overscrollBehaviorY = 'contain';
                } else {
                  sc.style.scrollSnapType = 'none';
                }
              }
            }, { passive: true });

            sc.addEventListener('touchend', () => {
              if (axis === 'y') {
                sc.style.scrollSnapType = '';
              }
              axis = null;
              sc.style.overscrollBehaviorY = '';
            }, { passive: true });
          },

          // ---- OBSERVERS ----
          setupObservers() {
            const canvas = this.$refs.canvas;
            const scrollContainer = this.$refs.scrollContainer;
            if (!canvas || !scrollContainer) return;

            this._resizeObserver = new ResizeObserver(() => {
              this.scheduleRecalculate();
            });
            this._resizeObserver.observe(canvas);
            this._resizeObserver.observe(scrollContainer);

            window.addEventListener('resize', () => {
              this.scheduleRecalculate();
            });
          },

          scheduleRecalculate() {
            if (this._recalcRAF) return;
            this._recalcRAF = requestAnimationFrame(() => {
              this._recalcRAF = null;
              this.recalculatePaths();
            });
          },

          // ---- SVG PATH CREATION ----
          createPaths() {
            const g = this.$refs.pathsGroup;
            if (!g) return;
            g.innerHTML = '';

            const svgNS = 'http://www.w3.org/2000/svg';

            this.relations.forEach((rel, i) => {
              // Visible path
              const path = document.createElementNS(svgNS, 'path');
              path.setAttribute('data-relation-index', i);
              path.setAttribute('fill', 'none');
              path.setAttribute('stroke', this.relationColors[rel.type] || '#ffffff');
              path.setAttribute('stroke-linecap', 'round');
              path.setAttribute('stroke-linejoin', 'round');
              path.style.pointerEvents = 'none';
              path.style.transition = 'opacity 0.2s ease, stroke-width 0.2s ease';
              path.setAttribute(
                'marker-end',
                this.needsEndMarker(rel) ? `url(#arrow-${rel.type})` : ''
              );
              path.setAttribute(
                'marker-start',
                this.needsStartMarker(rel) ? `url(#arrow-${rel.type})` : ''
              );
              g.appendChild(path);

              // Invisible hit path (wider, for mouse interaction)
              const hit = document.createElementNS(svgNS, 'path');
              hit.setAttribute('data-relation-hit-index', i);
              hit.setAttribute('fill', 'none');
              hit.setAttribute('stroke', 'transparent');
              hit.setAttribute('stroke-width', '16');
              hit.style.pointerEvents = 'stroke';
              hit.style.cursor = 'pointer';
              hit.addEventListener('mouseenter', (e) => this.handleRelationHover(rel, e));
              hit.addEventListener('mouseleave', () => this.handleRelationLeave());
              hit.addEventListener('mousemove', (e) => this.updateTooltipPosition(e));
              g.appendChild(hit);
            });

            this.updatePathStyles();
          },

          // ---- PATH GEOMETRY ----
          recalculatePaths() {
            const canvas = this.$refs.canvas;
            const g = this.$refs.pathsGroup;
            if (!canvas || !g) return;

            const canvasRect = canvas.getBoundingClientRect();

            this.relations.forEach((rel, i) => {
              const d = this.calculatePath(rel, canvasRect);
              if (!d) return;

              const path = g.querySelector(`[data-relation-index="${i}"]`);
              const hit = g.querySelector(`[data-relation-hit-index="${i}"]`);
              if (path) path.setAttribute('d', d);
              if (hit) hit.setAttribute('d', d);
            });
          },

          calculatePath(rel, canvasRect) {
            if (rel.project_id === rel.target_project_id) return null;

            const canvas = this.$refs.canvas;
            const sourceCard = canvas.querySelector(`[data-project-id="${rel.project_id}"]`);
            const targetCard = canvas.querySelector(`[data-project-id="${rel.target_project_id}"]`);
            if (!sourceCard || !targetCard) return null;

            const sr = sourceCard.getBoundingClientRect();
            const tr = targetCard.getBoundingClientRect();

            const sx = sr.left - canvasRect.left;
            const sy = sr.top - canvasRect.top;
            const tx = tr.left - canvasRect.left;
            const ty = tr.top - canvasRect.top;
            const sw = sr.width;
            const sh = sr.height;
            const tw = tr.width;
            const th = tr.height;

            const sourceStreamIdx = this.streamIndex(rel.project_id);
            const targetStreamIdx = this.streamIndex(rel.target_project_id);

            let startX, startY, endX, endY, cp1X, cp1Y, cp2X, cp2Y;

            if (sourceStreamIdx === targetStreamIdx) {
              // Same stream — curve out to the right
              startX = sx + sw;
              startY = sy + sh / 2;
              endX = tx + tw;
              endY = ty + th / 2;
              const curveOffset = Math.max(70, Math.abs(endY - startY) * 0.5 + 40);
              cp1X = startX + curveOffset;
              cp1Y = startY;
              cp2X = endX + curveOffset;
              cp2Y = endY;
            } else if (sourceStreamIdx < targetStreamIdx) {
              // Source is left of target — right edge to left edge
              startX = sx + sw;
              startY = sy + sh / 2;
              endX = tx;
              endY = ty + th / 2;
              const dx = (endX - startX) * 0.5;
              cp1X = startX + dx;
              cp1Y = startY;
              cp2X = endX - dx;
              cp2Y = endY;
            } else {
              // Source is right of target — left edge to right edge
              startX = sx;
              startY = sy + sh / 2;
              endX = tx + tw;
              endY = ty + th / 2;
              const dx = (startX - endX) * 0.5;
              cp1X = startX - dx;
              cp1Y = startY;
              cp2X = endX + dx;
              cp2Y = endY;
            }

            return `M ${startX.toFixed(1)} ${startY.toFixed(1)} C ${cp1X.toFixed(1)} ${cp1Y.toFixed(1)}, ${cp2X.toFixed(1)} ${cp2Y.toFixed(1)}, ${endX.toFixed(1)} ${endY.toFixed(1)}`;
          },

          // ---- PATH VISUAL STATE ----
          updatePathStyles() {
            const g = this.$refs.pathsGroup;
            if (!g) return;

            this.relations.forEach((rel, i) => {
              const path = g.querySelector(`[data-relation-index="${i}"]`);
              const hit = g.querySelector(`[data-relation-hit-index="${i}"]`);
              if (!path) return;
              path.setAttribute('stroke-width', this.pathWidth(rel));
              path.style.opacity = this.pathOpacity(rel);
              if (hit) hit.style.pointerEvents = this.activeRelationType === 'none' ? 'none' : 'stroke';
            });
          },

          pathOpacity(rel) {
            if (this.isSingleStream) return 0;
            if (this.activeRelationType === 'none') return 0;
            if (this.hoveredRelation === rel) return 0.95;

            const active = this.hoveredProject || this.selectedProject;
            if (active) {
              const connected =
                rel.project_id === active.project_id ||
                rel.target_project_id === active.project_id;
              return connected ? 0.8 : 0.04;
            }

            if (this.activeRelationType !== 'all' && rel.type !== this.activeRelationType) {
              return 0.04;
            }

            if (this.hasActiveProjectFilters) {
              const sourceMatches = this.projectMatchesFiltersById(rel.project_id);
              const targetMatches = this.projectMatchesFiltersById(rel.target_project_id);
              if (!sourceMatches && !targetMatches) return 0.04;
            }

            if (this.searchQuery.trim()) {
              const sourceMatch = this.isProjectMatchedById(rel.project_id);
              const targetMatch = this.isProjectMatchedById(rel.target_project_id);
              if (!sourceMatch && !targetMatch) return 0.04;
              return 0.35;
            }

            return 0.28;
          },

          pathWidth(rel) {
            if (this.hoveredRelation === rel) return 2.5;
            const active = this.hoveredProject || this.selectedProject;
            if (active && (rel.project_id === active.project_id || rel.target_project_id === active.project_id)) {
              return 2;
            }
            return 1.5;
          },

          // ---- DIRECTION MARKERS ----
          needsEndMarker(rel) {
            return Boolean(this.relationColors[rel.type]) && (rel.direction === 'outgoing' || rel.direction === 'bidirectional');
          },

          needsStartMarker(rel) {
            return Boolean(this.relationColors[rel.type]) && (rel.direction === 'incoming' || rel.direction === 'bidirectional');
          },

          // ---- INTERACTION: PROJECTS ----
          selectProject(project) {
            if (this.selectedProject && this.selectedProject.project_id === project.project_id) {
              this.selectedProject = null;
            } else {
              this.selectedProject = project;
            }
            this.updatePathStyles();
          },

          hoverProject(project) {
            this.hoveredProject = project;
            this.updatePathStyles();
          },

          leaveProject() {
            this.hoveredProject = null;
            this.updatePathStyles();
          },

          // ---- INTERACTION: RELATIONS ----
          handleRelationHover(rel, event) {
            this.hoveredRelation = rel;
            this.updateTooltipPosition(event);
            this.updatePathStyles();
          },

          handleRelationLeave() {
            this.hoveredRelation = null;
            this.updatePathStyles();
          },

          updateTooltipPosition(event) {
            const x = event.clientX;
            const y = event.clientY;
            const tw = 260;
            const th = 90;

            this.tooltipX = x + tw + 20 > window.innerWidth ? x - tw - 12 : x + 16;
            this.tooltipY = y + th + 20 > window.innerHeight ? y - th - 8 : y + 16;
          },

          // ---- CANVAS CLICK ----
          handleCanvasClick(e) {
            if (e.target.closest('[data-project-id]')) return;
            this.reset();
          },

          // ---- RESET ----
          reset() {
            this.selectedProject = null;
            this.hoveredProject = null;
            this.hoveredRelation = null;
            this.searchQuery = '';
            this.activeRelationType = 'all';
            this.filterableFields.forEach((f) => {
              this.projectFilters[f] = 'all';
            });
            this.updatePathStyles();
          },

          // ---- HELPERS: DATA ----
          projectsByStream(streamId) {
            return this.projects.filter((p) => p.stream_id === streamId);
          },

          streamIndex(projectId) {
            const project = this.projects.find((p) => p.project_id === projectId);
            if (!project) return -1;
            return this.streams.findIndex((s) => s.id === project.stream_id);
          },

          projectName(id) {
            if (!id) return '';
            const p = this.projects.find((p) => p.project_id === id);
            return p ? p.name : '';
          },

          // ---- HELPERS: PROJECT VISUAL STATE ----
          isProjectActive(projectId) {
            if (this.hoveredRelation) {
              return (
                this.hoveredRelation.project_id === projectId ||
                this.hoveredRelation.target_project_id === projectId
              );
            }
            return (
              (this.hoveredProject && this.hoveredProject.project_id === projectId) ||
              (this.selectedProject && this.selectedProject.project_id === projectId)
            );
          },

          isProjectConnected(projectId) {
            if (this.hoveredRelation) return false;
            const active = this.hoveredProject || this.selectedProject;
            if (!active) return false;
            return this.relations.some(
              (r) =>
                (r.project_id === active.project_id && r.target_project_id === projectId) ||
                (r.target_project_id === active.project_id && r.project_id === projectId)
            );
          },

          isProjectDimmed(project) {
            if (this.hoveredRelation) {
              return (
                project.project_id !== this.hoveredRelation.project_id &&
                project.project_id !== this.hoveredRelation.target_project_id
              );
            }

            const active = this.hoveredProject || this.selectedProject;
            if (active && project.project_id !== active.project_id && !this.isProjectConnected(project.project_id)) {
              return true;
            }

            if (!this.projectMatchesFilters(project)) {
              return true;
            }

            if (this.searchQuery.trim() && !this.isProjectMatched(project)) {
              return true;
            }

            return false;
          },

          // ---- HELPERS: SEARCH ----
          isProjectMatched(project) {
            if (!this.searchQuery.trim()) return true;
            const q = this.searchQuery.toLowerCase().trim();
            return (
              project.name.toLowerCase().includes(q) ||
              project.category.toLowerCase().includes(q) ||
              project.target.toLowerCase().includes(q) ||
              project.description.toLowerCase().includes(q)
            );
          },

          isProjectMatchedById(id) {
            const project = this.projects.find((p) => p.project_id === id);
            return project ? this.isProjectMatched(project) : false;
          },

          // ---- HELPERS: INFO PANEL ----
          connectedProjectsWithType(projectId) {
            if (!projectId) return [];
            const result = [];
            const seen = new Set();
            this.relations.forEach((r) => {
              if (r.project_id === projectId && !seen.has(r.target_project_id)) {
                seen.add(r.target_project_id);
                const p = this.projects.find((p) => p.project_id === r.target_project_id);
                if (p) result.push({ project: p, type: r.type, direction: r.direction, description: r.description });
              }
              if (r.target_project_id === projectId && !seen.has(r.project_id)) {
                seen.add(r.project_id);
                const p = this.projects.find((p) => p.project_id === r.project_id);
                if (p) result.push({ project: p, type: r.type, direction: r.direction, description: r.description });
              }
            });
            return result;
          },

          latestEventForProject(projectId) {
            const projectEvents = this.events.filter(
              (e) => e.project_id === projectId
            );
            if (!projectEvents.length) return null;
            return [...projectEvents].sort(
              (a, b) => new Date(b.timestamp) - new Date(a.timestamp)
            )[0];
          },

          eventColorForProject(projectId) {
            const event = this.latestEventForProject(projectId);
            if (!event) return '#3b82f6';
            const diffMin = (new Date() - new Date(event.timestamp)) / 60000;
            if (diffMin < 1) return '#ff1744';
            if (diffMin < 5) return '#ff5252';
            if (diffMin < 15) return '#ff7961';
            if (diffMin < 30) return '#ffa07a';
            if (diffMin < 60) return '#90caf9';
            return '#81d4fa';
          },

          eventLabelForProject(projectId) {
            const event = this.latestEventForProject(projectId);
            if (!event) return '';
            const diffMs = new Date() - new Date(event.timestamp);
            const floorMin = Math.floor(Math.abs(diffMs) / 60000);
            const floorHr = Math.floor(floorMin / 60);
            if (floorMin < 1) return 'just now';
            if (floorMin < 60) return floorMin + 'm ago';
            if (floorHr < 24) return floorHr + 'h ago';
            return Math.floor(floorHr / 24) + 'd ago';
          },

          hoverEvent(projectId, domEvent) {
            const evt = this.latestEventForProject(projectId);
            if (!evt) return;
            this.hoveredEvent = evt;
            this.eventTooltipX = domEvent.clientX + 12;
            this.eventTooltipY = domEvent.clientY - 8;
          },

          leaveEvent() {
            this.hoveredEvent = null;
          },

          toggleConnDescription(conn) {
            const key = conn.project.project_id + conn.type;
            this.expandedConns[key] = !this.expandedConns[key];
          },

          issueCountForProject(projectId) {
            if (!projectId) return 0;
            return this.githubIssues.filter((i) => i.project_id === projectId && i.state === 'open').length;
          },

          issuesForProject(projectId) {
            if (!projectId) return [];
            return this.githubIssues.filter((i) => i.project_id === projectId && i.state === 'open');
          },

          labelColor(label) {
            return this.labelColorMap[label] || '#6b7280';
          },

          openIssuesModal(project) {
            this.issuesModalProject = project;
          },

          closeIssuesModal() {
            this.issuesModalProject = null;
          },

          openPostsModal(project) {
            this.postsModalProject = project;
          },

          closePostsModal() {
            this.postsModalProject = null;
          },

          postCountForProject(projectId) {
            if (!projectId) return 0;
            return this.scheduledPosts.filter((p) => p.project_id === projectId && p.status === 'scheduled').length;
          },

          postsForProject(projectId) {
            if (!projectId) return [];
            return this.scheduledPosts.filter((p) => p.project_id === projectId && p.status === 'scheduled');
          },

          platformColor(platform) {
            return this.platformColorMap[platform] || '#6b7280';
          },

          platformIconSvg(platform) {
            return this.socialIconSvg(platform);
          },

          socialIconSvg(platform) {
            const icons = {
              twitter: '<svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>',
              linkedin: '<svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.063 2.063 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>',
              instagram: '<svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.012-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.781-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>',
              facebook: '<svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.999 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.072 24 12.073z"/></svg>',
              tiktok: '<svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor"><path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01.87-.01 1.74-.01 2.62.01 1.87-.34 3.77-1.34 5.37-1.27 2.05-3.52 3.37-5.86 3.57-1.96.16-3.99-.42-5.54-1.61-1.55-1.18-2.54-3.01-2.66-4.96-.02-.52-.03-1.04-.01-1.56.14-1.75.91-3.44 2.21-4.62 1.51-1.37 3.63-2.02 5.67-1.82.02 1.48.02 2.96.02 4.44-.81-.27-1.76-.18-2.49.34-.66.42-1.1 1.12-1.23 1.89-.1.67-.01 1.38.32 1.98.42.78 1.26 1.33 2.14 1.45.88.12 1.81-.13 2.47-.79.42-.42.65-1 .75-1.59.12-.68.07-1.37.08-2.05.01-3.42-.01-6.83.02-10.24z"/></svg>',
              bluesky: '<svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor"><path d="M5.202 2.857C7.954 4.922 10.913 9.11 12 11.358c1.087-2.247 4.046-6.436 6.798-8.501C20.783 1.366 24 .213 24 3.883c0 .732-.42 6.156-.667 7.037-.856 3.061-3.978 3.842-6.755 3.37 4.854.826 6.089 3.562 3.422 6.299-5.065 5.196-7.28-1.304-7.847-2.97-.104-.305-.152-.448-.153-.327 0-.121-.05.022-.153.327-.568 1.666-2.782 8.166-7.847 2.97-2.667-2.737-1.432-5.473 3.422-6.3-2.777.473-5.899-.308-6.755-3.369C.42 10.04 0 4.615 0 3.883c0-3.67 3.217-2.517 5.202-1.026"/></svg>',
              youtube: '<svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>',
              threads: '<svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor"><path d="M18.263 11.097c-.03-3.486-1.92-5.586-5.111-5.586-2.13 0-3.922.963-4.863 2.499l2.062 1.438c.535-.843 1.272-1.543 2.628-1.543 1.528 0 2.318.85 2.544 2.431a15 15 0 0 0-2.236-.173c-4.125 0-6.068 1.867-6.068 4.336s1.943 3.99 4.804 3.99c3.139 0 5.013-2.115 5.781-4.735.798.361 1.348 1.204 1.348 2.47 0 3.387-3.907 5.232-7.22 5.232-4.885 0-8.077-3.207-8.077-8.424 0-6.392 4.223-10.487 9.9-10.487 3.808 0 5.69 1.671 6.97 3.914l2.108-1.475C21.44 2.078 18.331 0 13.663 0 6.227 0 1.168 5.277 1.168 12.934c0 7 4.953 11.066 10.856 11.066 4.878 0 9.809-2.846 9.809-7.716 0-2.545-1.46-4.231-3.569-5.187m-6.33 4.855c-1.077 0-2.026-.512-2.026-1.453 0-1.483 1.822-1.934 3.606-1.934.678 0 1.34.045 1.927.173-.422 1.927-1.671 3.215-3.508 3.214Z"/></svg>',
            };
            return icons[platform] || '';
          },

          socialAccountCountForProject(projectId, network) {
            if (!projectId) return 0;
            const entry = this.socialFollowers.find((account) => account.project_id === projectId);
            if (!entry) return 0;
            return entry[network] || 0;
          },

          socialUrlForProject(projectId, network) {
            const project = this.projects.find((p) => p.project_id === projectId);
            if (!project) return '#';
            const handle = project.name.replace(/\./g, '');
            const urls = {
              linkedin: `https://linkedin.com/company/${handle}`,
              facebook: `https://facebook.com/${handle}`,
              instagram: `https://instagram.com/${handle}`,
              tiktok: `https://tiktok.com/@${handle}`,
              bluesky: `https://bsky.app/profile/${handle}.bsky.social`,
              youtube: `https://youtube.com/@${handle}`,
              threads: `https://threads.net/@${handle}`,
              twitter: `https://x.com/${handle}`,
            };
            return urls[network] || '#';
          },

          formatFollowers(count) {
            if (count >= 1000000) return (count / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
            if (count >= 1000) return (count / 1000).toFixed(1).replace(/\.0$/, '') + 'K';
            return String(count);
          },

          openTasksModal(project) {
            this.tasksModalProject = project;
          },

          closeTasksModal() {
            this.tasksModalProject = null;
          },

          taskCountForProject(projectId) {
            if (!projectId) return 0;
            return this.notionTasks.filter((t) => t.project_id === projectId).length;
          },

          tasksForProject(projectId) {
            if (!projectId) return [];
            return this.notionTasks.filter((t) => t.project_id === projectId);
          },

          taskPriorityColor(priority) {
            return this.taskPriorityColorMap[priority] || '#6b7280';
          },

          // ---- N8N WORKFLOWS METHODS ----
          openWorkflowsModal(project) {
            this.workflowsModalProject = project;
          },

          closeWorkflowsModal() {
            this.workflowsModalProject = null;
          },

          workflowCountForProject(projectId) {
            if (!projectId) return 0;
            return this.n8nWorkflows.filter((w) => w.project_id === projectId).length;
          },

          workflowsForProject(projectId) {
            if (!projectId) return [];
            return this.n8nWorkflows.filter((w) => w.project_id === projectId);
          },

          // ---- PROPERTIES METHODS ----
          get filteredProperties() {
            return this.properties.filter((p) => {
              if (this.activePropertyType !== 'all' && p.type !== this.activePropertyType) return false;
              if (this.activePropertyPurpose !== 'all' && p.purpose !== this.activePropertyPurpose) return false;
              if (this.propertySearchQuery.trim()) {
                const q = this.propertySearchQuery.toLowerCase().trim();
                if (!p.address.toLowerCase().includes(q) && !p.type.toLowerCase().includes(q)) return false;
              }
              return true;
            });
          },

          get utilitySuppliers() {
            return [...new Set(this.utilityInvoices.map((i) => i.supplier))].sort();
          },

          tenantsForProperty(propertyId) {
            return this.tenants.filter((t) => t.property_id === propertyId);
          },

          invoicesForProperty(propertyId) {
            return this.utilityInvoices.filter((i) => i.property_id === propertyId);
          },

          get filteredInvoices() {
            return this.utilityInvoices.filter((i) => {
              if (this.activeUtilityType !== 'all' && i.type !== this.activeUtilityType) return false;
              if (this.activeUtilitySupplier !== 'all' && i.supplier !== this.activeUtilitySupplier) return false;
              return true;
            });
          },

          totalInvoicesForProperty(propertyId) {
            return this.invoicesForProperty(propertyId).reduce((sum, i) => sum + i.amount, 0);
          },

          get totalUtilityCosts() {
            return this.filteredInvoices.reduce((sum, i) => sum + i.amount, 0);
          },

          get totalPropertyValue() {
            return this.filteredProperties.reduce((sum, p) => sum + p.acquisition_price, 0);
          },

          selectProperty(property) {
            if (this.selectedProperty && this.selectedProperty.property_id === property.property_id) {
              this.selectedProperty = null;
            } else {
              this.selectedProperty = property;
            }
          },

          closePropertyModal() {
            this.selectedProperty = null;
          },

          // ---- PROPERTY MODALS (documents, tenants, invoices) ----
          openDocumentsModal(property) {
            this.documentsModalProperty = property;
          },

          closeDocumentsModal() {
            this.documentsModalProperty = null;
          },

          documentsForProperty(propertyId) {
            if (!propertyId) return [];
            return this.propertyDocuments.filter((d) => d.property_id === propertyId);
          },

          openTenantsModal(property) {
            this.tenantsModalProperty = property;
          },

          closeTenantsModal() {
            this.tenantsModalProperty = null;
          },

          openInvoicesModal(property) {
            this.invoicesModalProperty = property;
          },

          closeInvoicesModal() {
            this.invoicesModalProperty = null;
          },

          unpaidInvoicesForProperty(propertyId) {
            if (!propertyId) return [];
            return this.utilityInvoices.filter((i) => i.property_id === propertyId && i.status === 'unpaid');
          },

          costsForProperty(propertyId) {
            return this.propertyCosts.filter((c) => c.property_id === propertyId);
          },

          revenuesForProperty(propertyId) {
            return this.propertyRevenues.filter((r) => r.property_id === propertyId);
          },

          chartDataForProperty(propertyId) {
            const costs = this.costsForProperty(propertyId);
            const revenues = this.revenuesForProperty(propertyId);
            const isMonth = this.chartAggregation === 'month';
            const buckets = {};

            const key = (dateStr) => {
              const d = new Date(dateStr);
              const y = d.getFullYear();
              const m = String(d.getMonth() + 1).padStart(2, '0');
              return isMonth ? `${y}-${m}` : `${y}`;
            };

            costs.forEach((c) => {
              const k = key(c.date);
              if (!buckets[k]) buckets[k] = { period: k, cost: 0, revenue: 0 };
              buckets[k].cost += c.amount;
            });
            revenues.forEach((r) => {
              const k = key(r.date);
              if (!buckets[k]) buckets[k] = { period: k, cost: 0, revenue: 0 };
              buckets[k].revenue += r.amount;
            });

            return Object.values(buckets).sort((a, b) => a.period.localeCompare(b.period));
          },

          chartCoords(values, w, h, padX, padY) {
            const max = Math.max(1, ...values);
            const min = Math.min(0, ...values);
            const range = max - min || 1;
            const n = values.length;
            if (n === 0) return [];
            return values.map((v, i) => ({
              x: padX + (i / Math.max(1, n - 1)) * (w - 2 * padX),
              y: h - padY - ((v - min) / range) * (h - 2 * padY),
            }));
          },

          chartPoints(values, w, h, padX, padY) {
            return this.chartCoords(values, w, h, padX, padY)
              .map((c) => `${c.x.toFixed(1)},${c.y.toFixed(1)}`)
              .join(' ');
          },

          chartAreaPath(values, w, h, padX, padY) {
            const coords = this.chartCoords(values, w, h, padX, padY);
            if (coords.length === 0) return '';
            let path = coords
              .map((c, i) => (i === 0 ? `M ${c.x.toFixed(1)},${c.y.toFixed(1)}` : `L ${c.x.toFixed(1)},${c.y.toFixed(1)}`))
              .join(' ');
            path += ` L ${coords[coords.length - 1].x.toFixed(1)},${(h - padY).toFixed(1)} L ${coords[0].x.toFixed(1)},${(h - padY).toFixed(1)} Z`;
            return path;
          },

          chartXPercent(i, n, w, padX) {
            if (n <= 1) return 50;
            return ((padX + (i / (n - 1)) * (w - 2 * padX)) / w) * 100;
          },

          chartPeriodLabel(period) {
            if (!period) return '';
            if (this.chartAggregation === 'month') {
              const [y, m] = period.split('-');
              const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
              return months[parseInt(m) - 1] + " '" + y.slice(2);
            }
            return period;
          },

          chartPeriodLabels(periods) {
            return periods.map((p) => this.chartPeriodLabel(p));
          },

          get totalUnpaidInvoices() {
            return this.utilityInvoices.filter((i) => i.status === 'unpaid').reduce((sum, i) => sum + i.amount, 0);
          },

          closeSearchResultProject() {
            this.searchResultProject = null;
          },

          closeSearchResultProperty() {
            this.searchResultProperty = null;
          },

          onProjectsSearchEnter() {
            const matches = this.projects.filter((p) => this.isProjectMatched(p));
            if (matches.length === 1) {
              this.searchResultProject = matches[0];
              this.searchQuery = '';
            }
          },

          onPropertiesSearchEnter() {
            const matches = this.filteredProperties;
            if (matches.length === 1) {
              this.searchResultProperty = matches[0];
              this.propertySearchQuery = '';
            }
          },

          setDashboard(dashboard) {
            this.activeDashboard = dashboard;
            this.selectedProperty = null;
            this.selectedProject = null;
            if (dashboard === 'projects') {
              this.$nextTick(() => {
                this.recalculatePaths();
                this.updatePathStyles();
              });
            }
          },

          formatCurrency(amount) {
            return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'EUR', minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(amount);
          },

          formatCurrencyPrecise(amount) {
            return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'EUR', minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(amount);
          },

          doRefresh() {
            this.isRefreshing = true;
            window.location.reload();
          },

          manualRefresh() {
            window.location.reload();
          },

          get refreshCountdownDisplay() {
            const m = Math.floor(this.refreshCountdown / 60);
            const s = this.refreshCountdown % 60;
            return `${m}:${String(s).padStart(2, '0')}`;
          },

          get refreshProgressPercent() {
            return ((this.refreshIntervalSeconds - this.refreshCountdown) / this.refreshIntervalSeconds) * 100;
          },

          get lastRefreshedDisplay() {
            if (!this.lastRefreshedAt) return '--';
            const d = this.lastRefreshedAt;
            const h = String(d.getHours()).padStart(2, '0');
            const m = String(d.getMinutes()).padStart(2, '0');
            const s = String(d.getSeconds()).padStart(2, '0');
            return `${h}:${m}:${s}`;
          },
        };
      }
    </script>


</x-filament-panels::page>
