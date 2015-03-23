DKAC - Gnutella Web Cache
=========================

DKAC is a bare bones implementation of the GWC2 standard documented at
http://gnucleus.sourceforge.net/gwebcache/newgwc.html

Running dkac on Red Hat's OpenShift PaaS
===========================================

You can quickly get a public instance of DKAC running with a free Openshift account.

Create an account at http://openshift.redhat.com/

Create a namespace, if you haven't already

    rhc domain create <yournamespace>

Create a php-5.4 application (you can name it anything via -a)

    rhc app create -a dkac -t php-5.4 -g small

Add this `github dkac` repository

    cd dkac
    git remote add upstream -m master https://github.com/rbedia/dkac.git
    git pull -s recursive -X theirs upstream master

Then push the repo to OpenShift

    git push

After the deploy finishes view the application at:

    http://dkac-$yournamespace.rhcloud.com
