# Copyright Siemens AG 2014
#
# Copying and distribution of this file, with or without modification,
# are permitted in any medium without royalty provided the copyright
# notice and this notice are preserved.  This file is offered as-is,
# without any warranty.

TOP = ../../../..
VARS = $(TOP)/Makefile.conf
DEPS = $(TOP)/Makefile.deps
include $(VARS)

LOCALAGENTDIR = ../../agent
DEF = -DDATADIR='"$(MODDIR)"'
CXXFLAGS_LOCAL = $(FO_CXXFLAGS) -Wall -I. -I$(LOCALAGENTDIR) -fopenmp
CXXFLAGS_LINK  = $(FO_CXXLDFLAGS) -lcppunit -fopenmp

EXE = test_scancode

OBJECTS = test_scancodewrapper.o
COVERAGE = $(OBJECTS:%.o=%_cov.o)

$(EXE): run_tests.cc $(OBJECTS) libscancode.a ${CXXFOLIB}
	$(CXX) run_tests.cc -o $@ $(OBJECTS) $(LOCALAGENTDIR)/libscancode.a $(CXXFLAGS_LINK)

$(OBJECTS): %.o: %.cc
	$(CXX) -c $(CXXFLAGS_LOCAL) $(DEF) $<

$(COVERAGE): %_cov.o: %.cc
	$(CXX) -c $< $(CXXFLAGS_LOCAL) $(FLAG_COV) $(DEF) $(DEFS) -o $@

$(CXXFOLIB):
	$(MAKE) -C $(CXXFOLIBDIR)

libscancode.a: $(OBJECTS)
	$(MAKE) -C $(LOCALAGENTDIR) $@

libscancode_cov.a: $(COVERAGE)
	$(MAKE) -C $(LOCALAGENTDIR) $@

all: $(EXE)

test: all
	./$(EXE)

coverage: run_tests.cc $(OBJECTS) libscancode_cov.a ${CXXFOLIB}
	$(CXX) run_tests.cc -o $(EXE) $(OBJECTS) $(LOCALAGENTDIR)/libscancode_cov.a $(CXXFLAGS_LINK) $(FLAG_COV)
	./$(EXE)

clean:
	rm -rf $(EXE) *.o *.a *.gcno *.gcda *Tests-Results.xml cov.txt results

.PHONY: all test coverage clean

include $(DEPS)
